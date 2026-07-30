<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Settings\SettingsService;
use App\Models\User;
use Modules\Docs\Models\Document;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * The export endpoints (wave 2.11): period by DUZP, the document cap, the audit
 * trail, and the refusals that must never produce a file.
 */
class AccountingExportTest extends DocsTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateModule($this->tenant, 'accounting');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function exportUrl(string $format, ?string $from = null, ?string $to = null): string
    {
        $from ??= now()->startOfMonth()->toDateString();
        $to ??= now()->endOfMonth()->toDateString();

        return "http://shop1.droidshop/admin/m/accounting/export?format={$format}&from={$from}&to={$to}";
    }

    private function issueInvoice(): Document
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);

        return Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
    }

    public function test_pohoda_export_streams_xml(): void
    {
        $invoice = $this->issueInvoice();

        $response = $this->actingAs($this->owner)->get($this->exportUrl('pohoda'));

        $response->assertOk();
        $response->assertHeader('x-robots-tag', 'noindex');
        $this->assertStringContainsString($invoice->number, $response->streamedContent());
    }

    public function test_isdoc_export_returns_a_zip(): void
    {
        $this->issueInvoice();

        $response = $this->actingAs($this->owner)->get($this->exportUrl('isdoc'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
    }

    public function test_an_empty_period_returns_a_message_and_no_file(): void
    {
        // A file with no documents reads as "nothing was sold", not as "wrong
        // period", so the nájemce is told instead of handed an empty export.
        $response = $this->actingAs($this->owner)->get($this->exportUrl(
            'pohoda',
            now()->subYear()->startOfMonth()->toDateString(),
            now()->subYear()->endOfMonth()->toDateString(),
        ));

        $response->assertRedirect();
        $response->assertSessionHas('status');
    }

    public function test_a_period_over_the_cap_is_refused(): void
    {
        $this->issueInvoice();
        config()->set('accounting.max_documents', 0);

        $this->actingAs($this->owner)
            ->get($this->exportUrl('pohoda'))
            ->assertSessionHasErrors('from');
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->get($this->exportUrl('money-s3'))
            ->assertSessionHasErrors('format');
    }

    public function test_the_settings_reach_the_generated_file(): void
    {
        $this->issueInvoice();
        app(SettingsService::class)->setMany('accounting', ['pohoda_predkontace_faktura' => '3Fv']);

        $body = $this->actingAs($this->owner)->get($this->exportUrl('pohoda'))->streamedContent();

        $this->assertStringContainsString('3Fv', $body);
    }

    public function test_a_single_document_can_be_downloaded_as_isdoc(): void
    {
        $invoice = $this->issueInvoice();

        $response = $this->actingAs($this->owner)->get(
            "http://shop1.droidshop/admin/m/accounting/isdoc/{$invoice->number}?type=invoice"
        );

        $response->assertOk();
        // isdoc() returns a plain Response, not a streamed one, so the
        // content is read directly rather than through streamedContent().
        $this->assertStringContainsString('<Invoice', $response->getContent());
    }

    public function test_a_proforma_number_is_not_served(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_PROFORMA);
        $proforma = Document::query()->where('type', Document::TYPE_PROFORMA)->latest('id')->firstOrFail();

        $this->actingAs($this->owner)->get(
            "http://shop1.droidshop/admin/m/accounting/isdoc/{$proforma->number}?type=proforma"
        )->assertNotFound();
    }

    public function test_a_member_without_the_permission_cannot_export(): void
    {
        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => json_encode([]),
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)->get($this->exportUrl('pohoda'))->assertForbidden();
    }

    public function test_every_export_is_audited(): void
    {
        $this->issueInvoice();

        $this->actingAs($this->owner)->get($this->exportUrl('pohoda'))->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $this->tenant->id,
            'action' => 'accounting.exported',
        ]);
    }

    public function test_a_failed_generation_leaves_no_audit_row(): void
    {
        // A document whose line carries a rate VatRateMap::pohoda() does not
        // know (21/12/0 are the only ones) makes writeDetail() throw
        // UnsupportedVatRate mid-loop — a real failure raised by the writer's
        // own code, the same lever IsdocFormatTest uses for "a document the
        // writer cannot honestly render". The export must not audit an
        // "exported" event for a file that never reached the nájemce.
        $invoice = $this->issueInvoice();

        $items = $invoice->items;
        $items[0]['tax_rate'] = '15.00';
        \DB::table('documents')->where('id', $invoice->id)->update(['items' => json_encode($items)]);

        $this->actingAs($this->owner)->get($this->exportUrl('pohoda'));

        $this->assertDatabaseMissing('audit_log', ['action' => 'accounting.exported']);
    }
}
