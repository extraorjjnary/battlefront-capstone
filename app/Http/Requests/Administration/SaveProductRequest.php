<?php

namespace App\Http\Requests\Administration;

use App\Actions\Catalog\CatalogName;
use App\Enums\ShippingProfile;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => CatalogName::normalize($this->input('name'))]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (! ($this->user()?->can('products.manage') ?? false)) {
            return false;
        }

        $product = $this->route('product');
        if (! $product instanceof Product) {
            return $this->user()->can('products.price')
                && (! $this->exists('acquisition_cost') || $this->user()->can('products.cost'));
        }

        $pricesUnchanged = is_numeric($this->input('price'))
            && bccomp($this->numericAmount($this->input('price')), $this->numericAmount($product->price), 2) === 0
            && ($this->input('discount_price') === null && $product->discount_price === null
                || is_numeric($this->input('discount_price')) && $product->discount_price !== null
                    && bccomp($this->numericAmount($this->input('discount_price')), $this->numericAmount($product->discount_price), 2) === 0);
        $costUnchanged = ! $this->exists('acquisition_cost')
            || ($this->input('acquisition_cost') === null && $product->acquisition_cost === null)
            || (is_numeric($this->input('acquisition_cost')) && $product->acquisition_cost !== null
                && bccomp($this->numericAmount($this->input('acquisition_cost')), $this->numericAmount($product->acquisition_cost), 2) === 0);

        return ($pricesUnchanged || $this->user()->can('products.price'))
            && ($costUnchanged || $this->user()->can('products.cost'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $importedCodeRule = $product instanceof Product && $product->is_catalog_imported
            ? [Rule::in([$product->product_code])]
            : [];

        return [
            'edit_version' => $product instanceof Product ? ['required', 'integer', 'min:1'] : ['exclude'],
            'price_change_reason' => $product instanceof Product ? ['nullable', 'string', 'min:5', 'max:1000'] : ['exclude'],
            'acquisition_cost' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'cost_change_reason' => $product instanceof Product ? ['nullable', 'string', 'min:5', 'max:1000'] : ['exclude'],
            'shipping_profile' => ['sometimes', 'required', Rule::enum(ShippingProfile::class)],
            'product_code' => $product instanceof Product ? [
                'bail', 'required', 'string', 'regex:'.Product::CODE_PATTERN,
                Rule::unique(Product::class, 'product_code')->ignore($product),
                ...$importedCodeRule,
            ] : ['exclude'],
            'name' => [
                'bail', 'required', 'string', 'max:255',
                CatalogName::uniqueRule(new Product, $product instanceof Product ? $product : null, CatalogName::PRODUCT_MESSAGE),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists(Category::class, 'id')->whereNull('deleted_at'),
            ],
            'brand' => ['bail', 'nullable', 'string', 'max:255'],
            'price' => [
                'bail',
                'required',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:9999999999.99',
            ],
            'is_featured' => ['required', 'boolean'],
            'discount_price' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0',
                'lt:price',
                'max:9999999999.99',
            ],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(Tag::class, 'id'),
            ],
        ];
    }

    /**
     * Get the validation messages for product details.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'edit_version.required' => 'Reload this product and review the latest changes before saving.',
            'product_code.required' => 'Enter a product code.',
            'product_code.regex' => 'Use 1 to 64 letters or digits for the product code.',
            'product_code.unique' => 'This product code is already in use.',
            'product_code.in' => 'Imported product codes cannot be changed.',
            'name.required' => 'Enter a product name.',
            'category_id.required' => 'Select a category.',
            'category_id.exists' => 'Select a valid category.',
            'price.required' => 'Enter the regular price.',
            'price.numeric' => 'The regular price must be a number.',
            'price.decimal' => 'Use no more than two decimal places for the regular price.',
            'price.min' => 'The regular price must be zero or greater.',
            'is_featured.required' => 'Choose whether this is a featured product.',
            'discount_price.numeric' => 'The discount price must be a number.',
            'discount_price.decimal' => 'Use no more than two decimal places for the discount price.',
            'discount_price.min' => 'The discount price must be zero or greater.',
            'discount_price.lt' => 'The discount price must be lower than the regular price.',
            'image.image' => 'Upload a valid product image.',
            'image.mimes' => 'The product image must be a JPG, JPEG, PNG, or WebP file.',
            'image.max' => 'The product image may not be larger than 5 MB.',
            'tag_ids.array' => 'Select product tags as a list.',
            'tag_ids.*.distinct' => 'Each tag may only be selected once.',
            'tag_ids.*.exists' => 'Select valid product tags.',
        ];
    }

    /** @return numeric-string */
    private function numericAmount(mixed $amount): string
    {
        if (! is_numeric($amount)) {
            throw new \InvalidArgumentException('A product price must be numeric.');
        }

        return (string) $amount;
    }
}
