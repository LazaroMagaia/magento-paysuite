<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Procura o contacto pelo email; se não existir cria-o. Devolve sempre o contact_id.
 */
class ContactResolver
{
    private const LIST_LIMIT = 100;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @throws LocalizedException
     */
    public function resolveId(OrderInterface $order): string
    {
        $email   = mb_strtolower(trim((string) $order->getCustomerEmail()));
        $storeId = (int) $order->getStoreId();

        if ($email === '') {
            throw new LocalizedException(__('A encomenda não tem email do cliente.'));
        }

        return $this->findByEmail($email, $storeId) ?? $this->create($order, $email, $storeId);
    }

    private function findByEmail(string $email, int $storeId): ?string
    {
        $response = $this->client->request(
            'GET',
            'contacts',
            ['email' => $email, 'limit' => self::LIST_LIMIT],
            null,
            $storeId
        );

        // O filtro pode não ser exacto: confirma o email exacto (case-insensitive).
        foreach ($response['data'] ?? [] as $contact) {
            if (isset($contact['id'], $contact['email']) && strcasecmp((string) $contact['email'], $email) === 0) {
                return (string) $contact['id'];
            }
        }

        return null;
    }

    private function create(OrderInterface $order, string $email, int $storeId): string
    {
        $payload = [
            'name'  => $this->resolveName($order, $email),
            'email' => $email,
        ];

        $phone = $this->normalizePhone((string) $order->getBillingAddress()?->getTelephone());
        if ($phone !== null) {
            $payload['phone'] = $phone;
        }

        $response = $this->client->request('POST', 'contacts', [], $payload, $storeId);
        $id = (string) ($response['data']['id'] ?? '');

        if ($id === '') {
            throw new LocalizedException(__('PaySuite: contacto criado sem id na resposta.'));
        }

        return $id;
    }

    private function resolveName(OrderInterface $order, string $email): string
    {
        $billing = $order->getBillingAddress();
        $name = trim($billing?->getFirstname() . ' ' . $billing?->getLastname());

        if ($name === '') {
            $name = trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname());
        }
        if ($name === '') {
            $name = strstr($email, '@', true) ?: $email;
        }

        return mb_substr($name, 0, 100);
    }

    /** E.164. Assume Moçambique (+258) para números locais de 9 dígitos. Devolve null se inválido. */
    private function normalizePhone(string $raw): ?string
    {
        $phone = preg_replace('/[\s().-]+/', '', $raw) ?? '';

        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        } elseif (preg_match('/^8\d{8}$/', $phone)) {
            $phone = '+258' . $phone;
        } elseif (preg_match('/^258\d{9}$/', $phone)) {
            $phone = '+' . $phone;
        }

        return preg_match('/^\+\d{8,15}$/', $phone) ? $phone : null;
    }
}
