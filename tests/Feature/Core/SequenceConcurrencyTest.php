<?php

namespace Tests\Feature\Core;

use App\Core\Sequences\SequenceService;
use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The lock has to be proven, not asserted. This forks real processes that
 * hammer the same series at once; without SELECT ... FOR UPDATE they collide
 * and hand out duplicate numbers.
 *
 * Not using RefreshDatabase: forked children open their own connections and
 * would not see data sitting in the parent's uncommitted test transaction, so
 * setup is committed and torn down by hand.
 */
class SequenceConcurrencyTest extends TestCase
{
    private ?int $tenantId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tenantId !== null) {
            DB::table('sequences')->where('tenant_id', $this->tenantId)->delete();
            DB::table('tenants')->where('id', $this->tenantId)->delete();
        }

        parent::tearDown();
    }

    public function test_concurrent_callers_never_share_a_number(): void
    {
        $plan = Plan::create([
            'key' => 'conc-'.uniqid(),
            'name' => 'Concurrency',
            'price_month' => 0,
            'price_year' => 0,
            'level' => 'base',
            'is_public' => false,
            'limits' => [],
        ]);

        $tenant = Tenant::create(['name' => 'Concurrency', 'status' => 'active', 'plan_id' => $plan->id]);
        $this->tenantId = $tenant->id;

        $workers = 4;
        $perWorker = 25;
        $expected = $workers * $perWorker;

        $dir = storage_path('framework/testing/seq-'.uniqid());
        @mkdir($dir, 0777, true);

        // Close the parent connection so children do not inherit its socket.
        DB::disconnect();

        $children = [];

        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                // Child: a fresh connection and context, then contend.
                DB::reconnect();
                $context = app(TenantContext::class);
                $sequences = app(SequenceService::class);

                $numbers = $context->runAs($tenant, function () use ($sequences, $perWorker) {
                    $out = [];
                    for ($i = 0; $i < $perWorker; $i++) {
                        $out[] = $sequences->next('orders');
                    }

                    return $out;
                });

                file_put_contents($dir."/{$w}.json", json_encode($numbers));
                exit(0);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        $all = [];
        foreach (glob($dir.'/*.json') as $file) {
            $all = array_merge($all, json_decode((string) file_get_contents($file), true));
        }
        array_map('unlink', glob($dir.'/*.json'));
        @rmdir($dir);

        $numbers = array_map('intval', $all);
        sort($numbers);

        $this->assertCount($expected, $numbers, 'Every call must produce a number.');
        $this->assertSame(count($numbers), count(array_unique($numbers)), 'No number may be handed out twice.');
        $this->assertSame(range(1, $expected), $numbers, 'The series must be contiguous with no gaps.');
    }
}
