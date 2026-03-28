<?php
/**
 * Reproduce symfony/symfony#57328 — OptionsResolver memory in nested forms.
 *
 * The issue: building large forms with nested collections causes
 * OptionsResolver to clone heavily and accumulate Closures
 * (particularly upload_max_size_message).
 */

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Forms;

$pid = getmypid();
echo "PID: $pid\n\n";

$formFactory = Forms::createFormFactory();
$memBaseline = memory_get_usage(true);
echo "Baseline: " . round($memBaseline / 1024 / 1024, 2) . " MB\n\n";

// Build a form with nested collections (simulating the issue scenario)
$numItems = 200;
echo "Building form with $numItems nested sub-forms (each with 8 fields)...\n";

$builder = $formFactory->createBuilder(FormType::class);

for ($i = 0; $i < $numItems; $i++) {
    $subBuilder = $formFactory->createBuilder(FormType::class)
        ->add("name_{$i}", TextType::class, ['label' => "Name $i"])
        ->add("email_{$i}", TextType::class, ['label' => "Email $i"])
        ->add("phone_{$i}", TextType::class, ['label' => "Phone $i"])
        ->add("age_{$i}", IntegerType::class, ['label' => "Age $i"])
        ->add("city_{$i}", TextType::class, ['label' => "City $i"])
        ->add("country_{$i}", ChoiceType::class, [
            'choices' => ['US' => 'us', 'UK' => 'uk', 'DE' => 'de', 'FR' => 'fr', 'JP' => 'jp'],
            'label' => "Country $i",
        ])
        ->add("notes_{$i}", TextType::class, ['label' => "Notes $i", 'required' => false])
        ->add("status_{$i}", ChoiceType::class, [
            'choices' => ['Active' => 'active', 'Inactive' => 'inactive', 'Pending' => 'pending'],
            'label' => "Status $i",
        ]);

    $builder->add("item_{$i}", FormType::class);
    // Copy children into the sub-form
    $itemBuilder = $builder->get("item_{$i}");
    foreach ($subBuilder->all() as $name => $child) {
        $itemBuilder->add($name, get_class($child->getType()->getInnerType()), $child->getOptions());
    }

    if (($i + 1) % 50 === 0) {
        $memNow = memory_get_usage(true);
        echo "  After $i sub-forms: " . round($memNow / 1024 / 1024, 2) . " MB\n";
    }
}

$memAfterBuild = memory_get_usage(true);
echo "\nAfter build: " . round($memAfterBuild / 1024 / 1024, 2) . " MB\n";

// Create the form (resolves all options)
echo "Creating form (resolves OptionsResolver)...\n";
$form = $builder->getForm();

$memAfterCreate = memory_get_usage(true);
echo "After create: " . round($memAfterCreate / 1024 / 1024, 2) . " MB\n";
echo "Delta from baseline: +" . round(($memAfterCreate - $memBaseline) / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
