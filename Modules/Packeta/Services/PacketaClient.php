<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Shipping\ShipmentResult;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Packeta REST/XML transport (wave 2.5).
 *
 * Only HTTP lives here — no order mapping, no persistence. REST/XML rather
 * than the SOAP WSDL Packeta also publishes: SOAP would add an ext-soap
 * dependency for no gain, and the Http facade is the precedent set by the
 * Comgate driver in wave 1.4.
 */
final class PacketaClient
{
    public function __construct(private readonly string $apiPassword) {}

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function createPacket(array $attributes): ShipmentResult
    {
        $xml = $this->request('createPacket', $this->buildPacketXml($attributes));

        $id = (string) ($xml->result->id ?? '');
        $barcode = (string) ($xml->result->barcode ?? '');

        if ($id === '') {
            throw CarrierError::rejected('packeta', 'odpověď neobsahuje id zásilky');
        }

        return new ShipmentResult($id, $barcode);
    }

    /**
     * @param  list<string>  $packetIds
     */
    public function labelsPdf(array $packetIds, string $format): string
    {
        $body = '<packetIds>';

        foreach ($packetIds as $index => $packetId) {
            $body .= '<id'.$index.'>'.htmlspecialchars($packetId, ENT_XML1).'</id'.$index.'>';
        }

        $body .= '</packetIds><format>'.htmlspecialchars($format, ENT_XML1).'</format><offset>0</offset>';

        $xml = $this->request('packetsLabelsPdf', $body);

        $pdf = base64_decode((string) ($xml->result ?? ''), true);

        if ($pdf === false || $pdf === '') {
            throw CarrierError::rejected('packeta', 'štítek se nepodařilo načíst');
        }

        return $pdf;
    }

    public function cancelPacket(string $packetId): void
    {
        $this->request('cancelPacket', '<packetId>'.htmlspecialchars($packetId, ENT_XML1).'</packetId>');
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function buildPacketXml(array $attributes): string
    {
        return '<packetAttributes>'.$this->elements($attributes).'</packetAttributes>';
    }

    /**
     * One nesting level is enough: Packeta's `size` is the only grouped
     * attribute we send (wave 3.8), and a general recursive serialiser would
     * be machinery for a case that does not exist.
     *
     * @param  array<string, scalar|array<string, scalar>|null>  $attributes
     */
    private function elements(array $attributes): string
    {
        $body = '';

        foreach ($attributes as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $body .= is_array($value)
                ? '<'.$key.'>'.$this->elements($value).'</'.$key.'>'
                : '<'.$key.'>'.htmlspecialchars((string) $value, ENT_XML1).'</'.$key.'>';
        }

        return $body;
    }

    private function request(string $method, string $body): SimpleXMLElement
    {
        $payload = sprintf(
            '<?xml version="1.0" encoding="utf-8"?><%1$s><apiPassword>%2$s</apiPassword>%3$s</%1$s>',
            $method,
            htmlspecialchars($this->apiPassword, ENT_XML1),
            $body,
        );

        try {
            $response = Http::withBody($payload, 'text/xml')
                ->timeout((int) config('packeta.timeout'))
                ->post((string) config('packeta.api_url'));
        } catch (Throwable $e) {
            throw CarrierError::unreachable('packeta', $e->getMessage());
        }

        if ($response->failed()) {
            throw CarrierError::unreachable('packeta', 'HTTP '.$response->status());
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            throw CarrierError::unreachable('packeta', 'odpověď není platné XML');
        }

        if ((string) ($xml->status ?? '') !== 'ok') {
            throw CarrierError::rejected('packeta', (string) ($xml->string ?? 'neznámý důvod'));
        }

        return $xml;
    }
}
