<?php
/**
 * Analyze Symfony Serializer memory with large object graphs.
 *
 * Symfony Serializer normalizes objects → arrays → JSON/XML.
 * The normalization step creates intermediate arrays proportional
 * to the object graph size. Tests denormalization memory too.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;

$pid = getmypid();
echo "PID: $pid\n\n";

// Define a nested DTO structure
class OrderDto {
    public function __construct(
        public readonly int $id,
        public readonly string $customer,
        public readonly string $date,
        /** @var OrderItemDto[] */
        public readonly array $items,
        public readonly AddressDto $address,
    ) {}
}

class OrderItemDto {
    public function __construct(
        public readonly int $productId,
        public readonly string $name,
        public readonly float $price,
        public readonly int $quantity,
        public readonly string $sku,
    ) {}
}

class AddressDto {
    public function __construct(
        public readonly string $street,
        public readonly string $city,
        public readonly string $zip,
        public readonly string $country,
    ) {}
}

$serializer = new Serializer(
    [new ObjectNormalizer(), new ArrayDenormalizer()],
    [new JsonEncoder()]
);

$memBaseline = memory_get_usage(true);

// Create many orders
$numOrders = 10000;
$itemsPerOrder = 5;
echo "Creating $numOrders orders ({$itemsPerOrder} items each)...\n";

$orders = [];
for ($i = 0; $i < $numOrders; $i++) {
    $items = [];
    for ($j = 0; $j < $itemsPerOrder; $j++) {
        $items[] = new OrderItemDto($j, "Product $j", 9.99 + $j, rand(1, 10), "SKU-$i-$j");
    }
    $orders[] = new OrderDto(
        $i, "Customer $i", date('Y-m-d', time() - $i * 86400),
        $items, new AddressDto("Street $i", "City", "1000$i", "US")
    );
}

$memAfterCreate = memory_get_usage(true);
echo "After creation: " . round($memAfterCreate / 1024 / 1024, 2) . " MB\n";

// Serialize
echo "\nSerializing to JSON...\n";
$json = $serializer->serialize($orders, 'json');
$memAfterSerialize = memory_get_usage(true);
echo "JSON size: " . round(strlen($json) / 1024 / 1024, 2) . " MB\n";
echo "After serialize: " . round($memAfterSerialize / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";

$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
