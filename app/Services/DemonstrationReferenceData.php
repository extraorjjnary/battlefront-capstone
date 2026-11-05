<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChatbotKnowledge;
use App\Models\PcBuildTemplate;
use App\Models\Product;
use App\Services\PcBuilder\ComponentSpecificationService;
use Database\Seeders\DevelopmentSupplierSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DemonstrationReferenceData
{
    public function prepare(bool $apply): void
    {
        app(DemonstrationProvisioning::class)->assertTarget();
        $branches = [
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122',
                'city' => 'Sagay City',
                'contact_number' => '0938 647 6046',
                'latitude' => 10.893690028077906,
                'longitude' => 123.41372677628254,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => null,
                'city' => 'Escalante City',
                'contact_number' => null,
                'latitude' => 10.84283002809064,
                'longitude' => 123.49853947111953,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'Carmona St., Brgy. V, San Carlos City, Negros Occidental, San Carlos City, Philippines 6127',
                'city' => 'San Carlos City',
                'contact_number' => null,
                'latitude' => 10.482947521818934,
                'longitude' => 123.42169185526996,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'L&E Arcade, Larena St., Brgy. Poblacion, Guihulngan City, Guihulngan, Philippines 6214',
                'city' => 'Guihulngan City',
                'contact_number' => '0947 946 5723',
                'latitude' => 10.119597,
                'longitude' => 123.273872,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'Downtown, Along SKG Shopping Center, Beside Ukay-Ukayan 58 Lizares St. Brgy. 13, Bacolod CIty, Philippines, 6100',
                'city' => 'Bacolod City',
                'contact_number' => '0961 176 4608',
                'latitude' => 10.671754246079693,
                'longitude' => 122.9470409276533,
            ],

        ];

        foreach ($branches as $branch) {
            $key = Str::slug($branch['city']);
            if (Branch::query()->where('seed_key', $key)->orWhere('city', $branch['city'])->exists()) {
                continue;
            }
            if ($apply) {
                $record = new Branch([...$branch,
                    'email' => config('battlefront.branch_emails.'.$branch['city']),
                    'operating_hours' => config('battlefront.operating_hours'),
                    'is_published' => filled($branch['address']) && filled(config('battlefront.operating_hours'))]);
                $record->forceFill(['seed_key' => $key])->save();
                DemonstrationProvisioning::audit('branch', $record->id);
            }
        }
        $faqs = [
            'How do I use PC Builder?' => 'Open PC Builder from the store navigation. Select components, review the build checks and current total, then save your build or continue through the available cart actions. The chatbot does not select parts or guarantee compatibility.',
            'How do I select components in PC Builder?' => 'Open PC Builder and choose a component category, then select an available component. Review its details, quantities and the build checks before continuing. Missing or draft specifications do not establish compatibility.',
            'How do saved PC builds work?' => 'Sign in with a customer account to save and load builds in PC Builder. Use the saved build sharing action to generate a public link; anyone with that link can view the shared build. Saving does not reserve stock or lock prices. Current availability and prices are checked again when purchasing.',
            'How do PC Builder templates work?' => 'Choose an available predefined template in PC Builder to load its components, then review or change your selections. Templates are shown only when their reviewed specifications, build checks and stock satisfy the current rules. A template may be unavailable; its saved budget is not a guaranteed checkout price.',
            'Where can I view PC Builder compatibility results?' => 'Review the checks in PC Builder after selecting components. Read each warning and missing-evidence result. Unknown or draft evidence is not verified compatibility, and incomplete builds are not complete compatible systems. These checks are not an installation or stability guarantee; the chatbot cannot judge your exact parts.',
            'How can I check my order status?' => 'Sign in and open your order history to view the latest status for your order.',
            'Do you offer real-time delivery tracking?' => 'Real-time delivery tracking is not available. Sign in and check your order history for the latest order status.',
            'How do I search for products?' => 'Open the product catalog and use search. You can narrow results by category, brand, or tag.',
            'How can I view a product\'s details?' => 'Open a product from the catalog to view its description, listed price, and availability.',
        ];

        foreach ($faqs as $question => $answer) {
            if (! ChatbotKnowledge::query()->where('question_pattern', $question)->exists() && $apply) {
                $record = ChatbotKnowledge::query()->create(['question_pattern' => $question,
                    'category' => 'faq', 'response_template' => $answer, 'priority' => 0, 'is_active' => true]);
                DemonstrationProvisioning::audit('chatbot_knowledge', $record->id);
            }
        }
        foreach (DevelopmentSupplierSeeder::catalog() as $supplier) {
            $existing = DB::table('suppliers')->where('name', $supplier['name'])->orWhere('email', $supplier['email'])->get();
            if ($existing->count() > 1 || ($existing->isNotEmpty()
                && ($existing->first()->name !== $supplier['name'] || $existing->first()->email !== $supplier['email']
                    || ! DemonstrationProvisioning::owns('supplier', $existing->first()->id)))) {
                throw new RuntimeException('Conflicting demonstration supplier.');
            }
            $products = Product::query()->whereIn('product_code', array_keys($supplier['products']))->get();
            if ($products->count() !== count($supplier['products'])) {
                throw new RuntimeException('Required supplier products are missing.');
            }
            if (! $apply) {
                continue;
            }
            $id = $existing->first()?->id;
            if ($id === null) {
                $id = DB::table('suppliers')->insertGetId(['name' => $supplier['name'], 'email' => $supplier['email'],
                    'contact_name' => 'Fictional demonstration contact', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                DemonstrationProvisioning::audit('supplier', $id);
            }
            foreach ($products as $product) {
                if (! DB::table('product_supplier')->where('supplier_id', $id)->where('product_id', $product->id)->exists()) {
                    DB::table('product_supplier')->insert(['supplier_id' => $id, 'product_id' => $product->id]);
                    DemonstrationProvisioning::audit('supplier_link', $id, ['product_id' => $product->id]);
                }
            }
        }
        foreach ([
            '91000000-0000-4000-8000-000000000001' => 'Budget Gaming PC',
            '91000000-0000-4000-8000-000000000002' => 'Mid-Range Gaming PC',
            '91000000-0000-4000-8000-000000000003' => 'High-End Gaming PC',
            '91000000-0000-4000-8000-000000000004' => 'Office PC',
            '91000000-0000-4000-8000-000000000005' => 'Student PC',
            '91000000-0000-4000-8000-000000000006' => 'Editing and Productivity PC',
        ] as $uuid => $name) {
            if (! PcBuildTemplate::query()->where('uuid', $uuid)->exists() && $apply) {
                $template = new PcBuildTemplate;
                $template->uuid = $uuid;
                $template->fill(['name' => $name, 'description' => 'Demonstration draft: review specifications before publication.',
                    'budget' => null, 'is_active' => false, 'edit_version' => 1])->save();
                DemonstrationProvisioning::audit('pc_build_template', $template->id);
            }
        }
        $contents = file_get_contents(resource_path('data/pc-builder-catalog.json'));
        if ($contents === false) {
            throw new RuntimeException('The PC Builder draft mapping is unavailable.');
        }
        $candidates = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        foreach ($candidates as $candidate) {
            $matches = Product::query()->customerEligible()->where('name', $candidate['name'])
                ->whereHas('category', fn ($query) => $query->where('name', $candidate['category']))->get();
            if ($matches->count() !== 1 || $matches->first()->componentProfile()->exists() || ! $apply) {
                continue;
            }
            $profile = app(ComponentSpecificationService::class)->saveDraft([
                'product_id' => $matches->first()->id, 'component_type' => $candidate['component_type'],
                'manufacturer_part_number' => $candidate['manufacturer_part_number'] ?? null, 'hardware_revision' => null,
                'specifications' => $candidate['specifications'] ?? [], 'evidence' => $candidate['evidence'] ?? [],
            ]);
            DemonstrationProvisioning::audit('component_profile', $profile->id);
        }
    }
}
