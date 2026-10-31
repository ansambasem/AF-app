<?php

namespace App\Http\Requests;

use App\Models\CompanyFormTemplate;
use App\Support\CompanyForms;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCompanyFormTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('company_form');

        return $template instanceof CompanyFormTemplate
            ? $this->user()->can('update', $template)
            : $this->user()->can('create', CompanyFormTemplate::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fieldKey = ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'];

        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'max:100'],
            'version' => [$this->route('company_form') ? 'required' : 'nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:10240'],
            'layout' => ['required', 'array:paper,orientation,margin,fontSize,header,watermark,footer,pages,overlays', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen((string) json_encode($value)) > 180000) {
                    $fail('التصميم أكبر من المسموح.');
                }
            }],
            'layout.paper' => ['required', Rule::in(['A4', 'A5', 'letter'])],
            'layout.orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'layout.margin' => ['required', 'numeric', 'between:5,30'],
            'layout.fontSize' => ['required', 'integer', 'between:11,24'],
            'layout.header' => ['required', 'array:company,subtitle,logo,enabled,style,logoPosition,logoWidth,logoHeight,english,arabicAlign,englishAlign,companyFont,companySize,subtitleSize,englishFont,englishSize,textColor,ruleStyle,ruleWidth,ruleColor,ruleColor2,ruleGap'],
            'layout.header.style' => ['nullable', Rule::in(['simple', 'letterhead'])],
            'layout.header.logoPosition' => ['nullable', Rule::in(['right', 'center', 'left'])],
            'layout.header.logoWidth' => ['nullable', 'numeric', 'between:10,60'],
            'layout.header.logoHeight' => ['nullable', 'numeric', 'between:8,50'],
            'layout.header.english' => ['nullable', 'string', 'max:300'],
            'layout.header.arabicAlign' => ['nullable', Rule::in(['right', 'center', 'left'])],
            'layout.header.englishAlign' => ['nullable', Rule::in(['right', 'center', 'left'])],
            'layout.header.companyFont' => ['nullable', Rule::in(['plex', 'messiri', 'alexandria', 'arial', 'times'])],
            'layout.header.englishFont' => ['nullable', Rule::in(['plex', 'messiri', 'alexandria', 'arial', 'times'])],
            'layout.header.companySize' => ['nullable', 'numeric', 'between:10,36'],
            'layout.header.subtitleSize' => ['nullable', 'numeric', 'between:9,28'],
            'layout.header.englishSize' => ['nullable', 'numeric', 'between:9,32'],
            'layout.header.textColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'layout.header.ruleStyle' => ['nullable', Rule::in(['none', 'single', 'double'])],
            'layout.header.ruleWidth' => ['nullable', 'numeric', 'between:0.5,6'],
            'layout.header.ruleColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'layout.header.ruleColor2' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'layout.header.ruleGap' => ['nullable', 'numeric', 'between:0.5,5'],
            'layout.header.company' => ['nullable', 'string', 'max:200'],
            'layout.header.subtitle' => ['nullable', 'string', 'max:300'],
            'layout.header.logo' => ['required', 'boolean'],
            'layout.header.enabled' => ['required', 'boolean'],
            'layout.watermark' => ['required', 'array:enabled,text,logo,opacity'],
            'layout.watermark.enabled' => ['required', 'boolean'],
            'layout.watermark.text' => ['nullable', 'string', 'max:150'],
            'layout.watermark.logo' => ['required', 'boolean'],
            'layout.watermark.opacity' => ['required', 'numeric', 'between:0.02,0.2'],
            'layout.footer' => ['nullable', 'string', 'max:500'],
            'layout.pages' => ['required', 'array', 'min:1', 'max:10'],
            'layout.pages.*' => ['required', 'array:id,blocks'],
            'layout.pages.*.id' => ['required', 'string', 'max:60', 'distinct'],
            'layout.pages.*.blocks' => ['present', 'array', 'max:35'],
            'layout.pages.*.blocks.*' => ['array:id,type,text,align,bold,fontSize,height,fields'],
            'layout.pages.*.blocks.*.id' => ['required', 'string', 'max:60'],
            'layout.pages.*.blocks.*.type' => ['required', Rule::in(['heading', 'paragraph', 'fields', 'signatures', 'spacer'])],
            'layout.pages.*.blocks.*.text' => ['nullable', 'string', 'max:6000'],
            'layout.pages.*.blocks.*.align' => ['required', Rule::in(['right', 'center', 'left'])],
            'layout.pages.*.blocks.*.bold' => ['required', 'boolean'],
            'layout.pages.*.blocks.*.fontSize' => ['nullable', 'integer', 'between:11,32'],
            'layout.pages.*.blocks.*.height' => ['nullable', 'numeric', 'between:1,60'],
            'layout.pages.*.blocks.*.fields' => ['sometimes', 'array', 'max:30'],
            'layout.pages.*.blocks.*.fields.*' => ['array:key,label'],
            'layout.pages.*.blocks.*.fields.*.key' => $fieldKey,
            'layout.pages.*.blocks.*.fields.*.label' => ['required', 'string', 'max:100'],
            'layout.overlays' => ['present', 'array', 'max:100'],
            'layout.overlays.*' => ['array:id,page,key,label,x,y,width,fontSize,align'],
            'layout.overlays.*.id' => ['required', 'string', 'max:60', 'distinct'],
            'layout.overlays.*.page' => ['required', 'integer', 'between:1,10'],
            'layout.overlays.*.key' => $fieldKey,
            'layout.overlays.*.label' => ['required', 'string', 'max:100'],
            'layout.overlays.*.x' => ['required', 'numeric', 'between:0,95'],
            'layout.overlays.*.y' => ['required', 'numeric', 'between:0,95'],
            'layout.overlays.*.width' => ['required', 'numeric', 'between:2,100'],
            'layout.overlays.*.fontSize' => ['required', 'integer', 'between:9,32'],
            'layout.overlays.*.align' => ['required', Rule::in(['right', 'center', 'left'])],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach (CompanyForms::fields($this->validated('layout')) as $field) {
                if (! isset(CompanyForms::FIELDS[$field['key']]) && ! str_starts_with($field['key'], 'custom_')) {
                    $validator->errors()->add('layout', 'اختر الحقول من قائمة البيانات أو أضف حقلًا مخصصًا.');
                }
            }
            foreach ($this->validated('layout.overlays', []) as $overlay) {
                if ((float) $overlay['x'] + (float) $overlay['width'] > 100) {
                    $validator->errors()->add('layout.overlays', 'يجب أن يبقى الحقل داخل حدود الورقة.');
                }
            }
        }];
    }
}
