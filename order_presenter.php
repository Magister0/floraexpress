<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('order_status_label')) {
    function order_status_label(?string $status): string
    {
        $map = [
            'new'         => 'Новый',
            'paid'        => 'Оплачен',
            'assembling'  => 'Собирается',
            'on_delivery' => 'В доставке',
            'delivered'   => 'Доставлен',
            'canceled'    => 'Отменён',
        ];
        $key = (string)$status;
        return $map[$key] ?? ($key !== '' ? $key : '—');
    }
}

if (!function_exists('order_status_class')) {
    function order_status_class(string $status): string
    {
        $map = [
            'new'         => 'tag tag--accent',
            'paid'        => 'tag tag--ok',
            'assembling'  => 'tag tag--accent',
            'on_delivery' => 'tag',
            'delivered'   => 'tag tag--ok',
            'canceled'    => 'tag tag--danger',
        ];
        return $map[$status] ?? 'tag tag--gray';
    }
}

if (!function_exists('delivery_status_label')) {
    function delivery_status_label(string $status): string
    {
        $map = [
            'pending'  => 'Ожидает курьера',
            'assigned' => 'Курьер назначен',
            'picked'   => 'Забрал заказ',
            'en_route' => 'В пути',
            'delivered'=> 'Доставлен',
            'failed'   => 'Не доставлен',
        ];
        return $map[$status] ?? ($status !== '' ? $status : 'Нет доставки');
    }
}

if (!function_exists('delivery_status_class')) {
    function delivery_status_class(string $status): string
    {
        $map = [
            'pending'  => 'tag tag--gray',
            'assigned' => 'tag tag--accent',
            'picked'   => 'tag tag--accent',
            'en_route' => 'tag',
            'delivered'=> 'tag tag--ok',
            'failed'   => 'tag tag--danger',
        ];
        return $map[$status] ?? 'tag tag--gray';
    }
}

if (!function_exists('delivery_method_label')) {
    function delivery_method_label(string $method): string
    {
        return $method === 'pickup' ? 'Самовывоз' : 'Доставка';
    }
}

if (!function_exists('delivery_method_badge_class')) {
    function delivery_method_badge_class(string $method): string
    {
        return $method === 'pickup' ? 'tag tag--gray' : 'tag tag--accent';
    }
}

if (!function_exists('infer_delivery_method')) {
    function infer_delivery_method(array $o, bool $hasDeliveryMethod): string
    {
        if ($hasDeliveryMethod && isset($o['delivery_method']) && $o['delivery_method'] !== '') {
            return (string)$o['delivery_method'];
        }
        $comment = (string)($o['comment'] ?? '');
        // "Способ: Самовывоз" пишется в comment при оформлении самовывоза
        if (mb_stripos($comment, 'самовывоз') !== false) {
            return 'pickup';
        }
        return 'delivery';
    }
}

if (!function_exists('short_address')) {
    function short_address(string $address, string $comment): string
    {
        $address = trim($address);
        if ($address === '') return '—';
        $address = preg_replace('/^Адрес:\s*/ui', '', $address);
        $line = explode("\n", $address)[0];
        // UTF-8-безопасная обрезка по символам (через PCRE /u, не требует mbstring)
        if (preg_match_all('/./us', $line) > 60) {
            if (preg_match('/^.{0,57}/us', $line, $m)) {
                $line = $m[0] . '…';
            }
        }
        return $line;
    }
}

if (!function_exists('placeholder_image')) {
    function placeholder_image(): string
    {
        return 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120">'
            . '<rect width="100%" height="100%" rx="18" fill="#f3f4f6"/>'
            . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="12" fill="#9ca3af">Нет фото</text>'
            . '</svg>'
        );
    }
}

if (!function_exists('image_src')) {
    function image_src(string $image, string $fallback): string
    {
        $image = trim($image);
        if ($image === '') return $fallback;
        if (preg_match('~^https?://~i', $image) || str_starts_with($image, 'data:image/') || $image[0] === '/') {
            return $image;
        }
        return $image;
    }
}

if (!function_exists('split_comment_parts')) {
    function split_comment_parts(?string $comment): array
    {
        $comment = trim((string)$comment);
        if ($comment === '') return ['details' => [], 'note' => ''];

        $details = [];
        $note = '';
        $parts = preg_split('/\s*;\s*/u', $comment) ?: [];

        foreach ($parts as $part) {
            if ($part === '') continue;
            if (str_contains($part, ':')) {
                [$k, $v] = array_map('trim', explode(':', $part, 2));
                if ($k !== '') $details[$k] = $v;
            } else {
                $note .= ($note !== '' ? '; ' : '') . $part;
            }
        }

        if (isset($details['Комментарий']) && trim((string)$details['Комментарий']) !== '') {
            $note = trim((string)$details['Комментарий']);
            unset($details['Комментарий']);
        }

        return ['details' => $details, 'note' => $note];
    }
}

if (!function_exists('detail_value')) {
    function detail_value(array $details, string $key): string
    {
        return trim((string)($details[$key] ?? ''));
    }
}
