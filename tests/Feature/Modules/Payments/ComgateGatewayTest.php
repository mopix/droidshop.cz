<?php

namespace Tests\Feature\Modules\Payments;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Payments\Exceptions\GatewayError;
use App\Core\Payments\PaymentStatus;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Services\ComgateGateway;
use Modules\Payments\Support\ComgateSignature;
use Tests\TestCase;

/**
 * Unit-level: the driver is exercised directly against a faked HTTP gateway,
 * with a stub OrderBook. No tenant/DB needed — the gateway reads the order
 * through the contract and speaks the Comgate protocol, nothing more.
 */
class ComgateGatewayTest extends TestCase
{
    private const CONFIG = ['base_url' => 'https://payments.comgate.cz/v1.0', 'timeout' => 15];

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function gateway(?OrderBook $orders = null): ComgateGateway
    {
        return new ComgateGateway(
            merchant: 'M-123',
            secret: 's3cr3t',
            test: true,
            orders: $orders ?? \Mockery::mock(OrderBook::class),
            config: self::CONFIG,
        );
    }

    private function orderBookReturning(?OrderView $view): OrderBook
    {
        $book = \Mockery::mock(OrderBook::class);
        $book->shouldReceive('findForAdmin')->andReturn($view);

        return $book;
    }

    private function order(): OrderView
    {
        $view = \Mockery::mock(OrderView::class);
        $view->shouldReceive('orderTotal')->andReturn(new Money(12500, 'CZK'));
        $view->shouldReceive('orderCurrency')->andReturn('CZK');
        $view->shouldReceive('orderNumber')->andReturn('2026001');
        $view->shouldReceive('orderEmail')->andReturn('kupujici@example.com');

        return $view;
    }

    public function test_initiate_creates_a_payment_and_returns_the_redirect_and_reference(): void
    {
        Http::fake([
            '*/create' => Http::response('code=0&message=OK&transId=XYZ-9&redirect=https%3A%2F%2Fpayments.comgate.cz%2Fpay%2FXYZ-9', 200),
        ]);

        $initiation = $this->gateway($this->orderBookReturning($this->order()))->initiate('order-uuid');

        $this->assertSame('XYZ-9', $initiation->reference());
        $this->assertSame('https://payments.comgate.cz/pay/XYZ-9', $initiation->redirectUrl());

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/create')
                && $request['merchant'] === 'M-123'
                && $request['secret'] === 's3cr3t'
                && $request['price'] === '12500'
                && $request['curr'] === 'CZK'
                && $request['refId'] === '2026001'
                && $request['test'] === 'true'
                && $request['prepareOnly'] === 'true';
        });
    }

    public function test_initiate_on_a_missing_order_throws(): void
    {
        Http::fake();

        $this->expectException(GatewayError::class);

        $this->gateway($this->orderBookReturning(null))->initiate('nope');
    }

    public function test_initiate_throws_when_the_gateway_rejects_the_request(): void
    {
        Http::fake([
            '*/create' => Http::response('code=1400&message=Database%20error', 200),
        ]);

        $this->expectException(GatewayError::class);

        $this->gateway($this->orderBookReturning($this->order()))->initiate('order-uuid');
    }

    public function test_initiate_throws_when_the_response_lacks_a_redirect(): void
    {
        Http::fake([
            '*/create' => Http::response('code=0&message=OK&transId=XYZ-9', 200),
        ]);

        $this->expectException(GatewayError::class);

        $this->gateway($this->orderBookReturning($this->order()))->initiate('order-uuid');
    }

    public function test_verify_maps_paid_and_reads_the_gateway_amount(): void
    {
        Http::fake([
            '*/status' => Http::response('code=0&message=OK&status=PAID&price=12500&curr=CZK', 200),
        ]);

        $result = $this->gateway()->verify('XYZ-9');

        $this->assertSame(PaymentStatus::Paid, $result->status);
        $this->assertSame('XYZ-9', $result->reference);
        $this->assertTrue($result->amount->equals(new Money(12500, 'CZK')));
    }

    public function test_verify_maps_cancelled_to_failed(): void
    {
        Http::fake([
            '*/status' => Http::response('code=0&message=OK&status=CANCELLED&price=12500&curr=CZK', 200),
        ]);

        $this->assertSame(PaymentStatus::Failed, $this->gateway()->verify('XYZ-9')->status);
    }

    public function test_verify_maps_pending_and_anything_unknown_to_pending(): void
    {
        Http::fake([
            '*/status' => Http::response('code=0&message=OK&status=PENDING&price=12500&curr=CZK', 200),
        ]);

        $this->assertSame(PaymentStatus::Pending, $this->gateway()->verify('XYZ-9')->status);
    }

    public function test_verify_throws_on_transport_failure(): void
    {
        Http::fake([
            '*/status' => Http::response('', 500),
        ]);

        $this->expectException(GatewayError::class);

        $this->gateway()->verify('XYZ-9');
    }

    // --- notification signature ---------------------------------------------

    public function test_signature_matches_the_stored_secret_and_rejects_others(): void
    {
        $this->assertTrue(ComgateSignature::matches('s3cr3t', 's3cr3t'));
        $this->assertFalse(ComgateSignature::matches('wrong', 's3cr3t'));
        $this->assertFalse(ComgateSignature::matches(null, 's3cr3t'));
        $this->assertFalse(ComgateSignature::matches('', 's3cr3t'));
    }
}
