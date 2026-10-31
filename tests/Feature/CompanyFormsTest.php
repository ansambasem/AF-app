<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CompanyDocument;
use App\Models\CompanyFormTemplate;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CompanyForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CompanyFormsTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $actor = User::factory()->collector()->create();
        $actor->permissions()->sync(Permission::idsFor([PermissionKey::PrintCompanyForms]));

        return $actor->unsetRelation('permissions');
    }

    /** @return array<string, mixed> */
    private function issue(CompanyFormTemplate $template, array $overrides = []): array
    {
        return ['operation_id' => (string) Str::uuid(), 'mode' => 'filled', 'company_form_template_id' => $template->id, 'template_version' => $template->version, 'values' => [], ...$overrides];
    }

    public function test_printing_and_design_permissions_are_separate_and_visible_in_navigation(): void
    {
        $template = CompanyFormTemplate::factory()->create();
        $operator = $this->operator();

        $this->get(route('company-forms.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->collector()->create())->get(route('company-forms.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('company-forms.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManage', false)->where('canIssue', true)->where('can.viewCompanyForms', true));
        $this->get(route('company-forms.edit', $template))->assertForbidden();
        $this->post(route('company-forms.store'), ['name' => 'ممنوع'])->assertForbidden();

        $manager = User::factory()->collector()->withPermissions([PermissionKey::ManagePrintTemplates])->create();
        $this->assertTrue($manager->can('create', CompanyFormTemplate::class));
        $this->assertFalse($manager->can('create', CompanyDocument::class));
        $this->actingAs($manager)->post(route('company-documents.store'), $this->issue($template))->assertForbidden();
    }

    public function test_a_manager_creates_and_updates_a_layout_and_rejects_a_stale_version(): void
    {
        $manager = User::factory()->superAdmin()->create();
        $layout = CompanyForms::defaultLayout();
        $layout['header']['enabled'] = '0';
        $layout['watermark']['enabled'] = '0';

        $this->actingAs($manager)->post(route('company-forms.store'), ['name' => 'طلب اشتراك جديد', 'category' => 'الاشتراكات', 'layout' => $layout])->assertSessionHasNoErrors();
        $template = CompanyFormTemplate::sole();
        $this->assertFalse($template->layout['header']['enabled']);
        $this->assertFalse($template->layout['watermark']['enabled']);
        $this->assertSame($manager->id, $template->created_by);

        $this->post(route('company-forms.update', $template), ['name' => 'طلب معدل', 'category' => 'الاشتراكات', 'layout' => CompanyForms::defaultLayout(), 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(2, $template->fresh()->version);
        $this->post(route('company-forms.update', $template), ['name' => 'تعديل قديم', 'category' => 'الاشتراكات', 'layout' => CompanyForms::defaultLayout(), 'version' => 1])->assertSessionHasErrors('version');
        $this->assertSame('طلب معدل', $template->fresh()->name);
    }

    public function test_a_formal_letterhead_keeps_its_logo_texts_and_coloured_lines_and_rejects_unsafe_values(): void
    {
        $manager = User::factory()->superAdmin()->create();
        $layout = CompanyForms::defaultLayout();
        $layout['header'] = [...$layout['header'],
            'style' => 'letterhead', 'logoPosition' => 'center', 'logoWidth' => 32, 'logoHeight' => 24,
            'english' => "Fares Abu Zayed Sons\nGeneral Trading", 'arabicAlign' => 'right', 'englishAlign' => 'left',
            'companyFont' => 'messiri', 'companySize' => 20, 'subtitleSize' => 13, 'englishFont' => 'times', 'englishSize' => 15, 'textColor' => '#111827',
            'ruleStyle' => 'double', 'ruleWidth' => 2.5, 'ruleColor' => '#111827', 'ruleColor2' => '#a51d26', 'ruleGap' => 1.5,
        ];

        $this->actingAs($manager)->post(route('company-forms.store'), ['name' => 'طلب اشتراك جديد', 'category' => 'الاشتراكات', 'layout' => $layout])->assertSessionHasNoErrors();
        $this->assertSame($layout['header'], CompanyFormTemplate::sole()->layout['header']);

        foreach ([
            'layout.header.logoPosition' => ['logoPosition' => 'top'],
            'layout.header.ruleColor2' => ['ruleColor2' => 'red;background:url(x)'],
            'layout.header.ruleWidth' => ['ruleWidth' => 9],
            'layout.header.companyFont' => ['companyFont' => 'Comic Sans'],
            'layout.header.style' => ['style' => 'fancy'],
        ] as $field => $bad) {
            $broken = $layout;
            $broken['header'] = [...$layout['header'], ...$bad];
            $this->post(route('company-forms.store'), ['name' => 'نموذج خاطئ', 'category' => 'عام', 'layout' => $broken])->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('company_form_templates', 1);
    }

    public function test_invalid_layout_keys_fields_and_geometry_are_rejected(): void
    {
        $manager = User::factory()->superAdmin()->create();
        $layout = CompanyForms::defaultLayout();
        $layout['script'] = '<script>alert(1)</script>';

        $this->actingAs($manager)->post(route('company-forms.store'), ['name' => 'نموذج', 'category' => 'عام', 'layout' => $layout])->assertSessionHasErrors('layout');
        unset($layout['script']);
        $layout['pages'][0]['blocks'][1]['text'] = '{password}';
        $this->post(route('company-forms.store'), ['name' => 'نموذج', 'category' => 'عام', 'layout' => $layout])->assertSessionHasErrors('layout');
        $layout = CompanyForms::defaultLayout();
        $layout['overlays'] = [['id' => 'field_1', 'page' => 1, 'key' => 'subscriber_name', 'label' => 'الاسم', 'x' => 90, 'y' => 20, 'width' => 40, 'fontSize' => 15, 'align' => 'right']];
        $this->post(route('company-forms.store'), ['name' => 'نموذج', 'category' => 'عام', 'layout' => $layout])->assertSessionHasErrors('layout.overlays');

        $this->assertDatabaseCount('company_form_templates', 0);
    }

    public function test_sources_are_private_and_old_source_files_remain_available_to_issued_documents(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $manager = User::factory()->superAdmin()->create();
        $this->actingAs($manager)->post(route('company-forms.store'), ['name' => 'نموذج قديم', 'category' => 'عام', 'layout' => CompanyForms::defaultLayout(), 'source' => UploadedFile::fake()->image('old.png')])->assertSessionHasNoErrors();
        $template = CompanyFormTemplate::sole();
        $oldPath = $template->source_path;
        Storage::disk('local')->assertExists($oldPath);
        $this->get(route('company-forms.source', $template))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->post(route('company-documents.store'), $this->issue($template, ['mode' => 'blank']))->assertSessionHasNoErrors();
        $document = CompanyDocument::sole();
        $this->post(route('company-forms.update', $template), ['name' => 'نسخة جديدة', 'category' => 'عام', 'layout' => CompanyForms::defaultLayout(), 'version' => 1, 'source' => UploadedFile::fake()->image('new.png')])->assertSessionHasNoErrors();
        $this->assertNotSame($oldPath, $template->fresh()->source_path);
        Storage::disk('local')->assertExists($oldPath);
        $this->delete(route('company-forms.destroy', $template))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($template);
        $this->get(route('company-documents.source', $document))->assertOk();
        $this->get(route('company-documents.show', $document))->assertInertia(fn ($page) => $page->where('document.title', 'نموذج قديم')->where('document.snapshot.source.name', 'old.png'));
    }

    public function test_source_upload_rejects_executable_content_even_with_a_pdf_filename(): void
    {
        Storage::fake('local');
        $manager = User::factory()->superAdmin()->create();

        $content = UploadedFile::fake()->createWithContent('unsafe.pdf', '<html><script>alert(1)</script></html>');
        $file = new UploadedFile($content->getPathname(), 'unsafe.pdf', null, null, true);
        $this->actingAs($manager)->post(route('company-forms.store'), ['name' => 'نموذج', 'category' => 'عام', 'layout' => CompanyForms::defaultLayout(), 'source' => $file])->assertSessionHasErrors('source');

        $this->assertDatabaseCount('company_form_templates', 0);
    }

    public function test_filled_documents_use_scoped_subscriber_data_and_keep_an_immutable_snapshot(): void
    {
        $actor = $this->operator();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'full_name' => 'أحمد', 'phone' => '0591111111']);
        $template = CompanyFormTemplate::factory()->create();
        $originalLayout = $template->layout;

        $this->actingAs($actor)->post(route('company-documents.store'), $this->issue($template, ['subscription_id' => $subscription->id, 'values' => ['phone' => '0562222222', 'password' => 'ignored']]))->assertSessionHasNoErrors();
        $document = CompanyDocument::sole();
        $this->assertSame('أحمد', $document->snapshot['values']['subscriber_name']);
        $this->assertSame('0562222222', $document->snapshot['values']['phone']);
        $this->assertArrayNotHasKey('password', $document->snapshot['values']);
        $this->assertSame($actor->branch_id, $document->branch_id);
        $this->assertSame($actor->id, $document->created_by);
        $this->assertSame('0591111111', $subscription->fresh()->phone);

        $template->update(['name' => 'تغيير لاحق', 'layout' => [...$originalLayout, 'footer' => 'تذييل جديد'], 'version' => 2]);
        $subscription->update(['full_name' => 'اسم جديد']);
        $this->assertSame($originalLayout, $document->fresh()->snapshot['layout']);
        $this->assertSame('أحمد', $document->fresh()->snapshot['values']['subscriber_name']);
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_blank_documents_store_no_personal_values_and_can_be_issued_without_a_subscriber(): void
    {
        $actor = $this->operator();
        $template = CompanyFormTemplate::factory()->create();

        $this->actingAs($actor)->post(route('company-documents.store'), $this->issue($template, ['mode' => 'blank', 'values' => ['subscriber_name' => 'يجب ألا يظهر']]))->assertSessionHasNoErrors();

        $document = CompanyDocument::sole();
        $this->assertSame([], $document->snapshot['values']);
        $this->assertNull($document->subscription_id);
        $this->assertSame('blank', $document->mode);
    }

    public function test_retries_return_the_original_document_and_a_stale_template_cannot_be_issued(): void
    {
        $actor = $this->operator();
        $template = CompanyFormTemplate::factory()->create();
        $payload = $this->issue($template, ['mode' => 'blank']);
        $this->actingAs($actor)->post(route('company-documents.store'), $payload)->assertSessionHasNoErrors();
        $document = CompanyDocument::sole();
        $template->update(['version' => 2]);

        $this->post(route('company-documents.store'), $payload)->assertRedirect(route('company-documents.show', $document));
        $this->assertDatabaseCount('company_documents', 1);
        $this->post(route('company-documents.store'), $this->issue($template, ['template_version' => 1]))->assertSessionHasErrors('template_version');
        $this->assertDatabaseCount('company_documents', 1);
    }

    public function test_foreign_subscriptions_context_and_documents_are_not_exposed(): void
    {
        $actor = $this->operator();
        $foreign = Subscription::factory()->create();
        $foreignDocument = CompanyDocument::factory()->create(['branch_id' => $foreign->branch_id]);
        $template = CompanyFormTemplate::factory()->create();

        $this->actingAs($actor)->getJson(route('company-forms.subscriptions', ['id' => $foreign->id]))->assertNotFound();
        $this->post(route('company-documents.store'), $this->issue($template, ['subscription_id' => $foreign->id]))->assertNotFound();
        $this->get(route('company-documents.show', $foreignDocument))->assertNotFound();
        $this->get(route('company-documents.source', $foreignDocument))->assertNotFound();
        $this->get(route('company-forms.index', ['view' => 'documents']))->assertInertia(fn ($page) => $page->has('documents.data', 0));
    }

    public function test_search_and_subscription_context_only_include_the_work_branch_and_preserve_zero_values(): void
    {
        $actor = $this->operator();
        $own = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'full_name' => 'أحمد', 'initial_reading' => 0]);
        Subscription::factory()->create(['full_name' => 'أحمد']);

        $this->actingAs($actor)->getJson(route('company-forms.subscriptions', ['search' => 'أحمد']))->assertJsonCount(1, 'options')->assertJsonPath('options.0.value', (string) $own->id);
        $this->getJson(route('company-forms.subscriptions', ['id' => $own->id]))->assertJsonPath('values.subscriber_name', 'أحمد')->assertJsonPath('values.previous_reading', '0');
    }

    public function test_a_paper_application_is_archived_with_its_file_reference_date_and_optional_subscription(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $actor = $this->operator();
        $subscription = Subscription::factory()->create(['branch_id' => $actor->branch_id]);

        $this->actingAs($actor)->post(route('company-documents.store'), [
            'operation_id' => (string) Str::uuid(), 'mode' => 'archive', 'title' => 'طلب ورقي', 'paper_reference' => 'EXT-0041',
            'paper_date' => '2026-10-19', 'subscription_id' => $subscription->id, 'notes' => 'سُجّل أثناء انقطاع الإنترنت',
            'attachment' => UploadedFile::fake()->image('application.png'),
        ])->assertSessionHasNoErrors();

        $document = CompanyDocument::sole();
        $this->assertSame('EXT-0041', $document->paper_reference);
        $this->assertSame('2026-10-19', $document->paper_date->toDateString());
        $this->assertSame($subscription->id, $document->subscription_id);
        $this->assertSame($actor->branch_id, $document->branch_id);
        Storage::disk('local')->assertExists($document->attachment_path);
        $this->get(route('company-documents.source', $document))->assertOk();
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_paper_archives_require_the_reference_date_and_file(): void
    {
        $actor = $this->operator();

        $this->actingAs($actor)->post(route('company-documents.store'), ['operation_id' => (string) Str::uuid(), 'mode' => 'archive', 'title' => 'طلب'])
            ->assertSessionHasErrors(['paper_reference', 'paper_date', 'attachment']);

        $this->assertDatabaseCount('company_documents', 0);
    }

    public function test_branch_domains_keep_company_templates_shared_and_issued_documents_scoped_even_for_super_admin(): void
    {
        config(['app.url' => 'https://admin.company.test', 'powercollect.branch_domains_enabled' => true]);
        $branch = Branch::factory()->create(['domain' => 'north.company.test', 'domain_enabled' => true]);
        $own = CompanyDocument::factory()->create(['branch_id' => $branch->id]);
        $foreign = CompanyDocument::factory()->create();
        $template = CompanyFormTemplate::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('https://north.company.test/company-forms')->assertInertia(fn ($page) => $page
            ->has('templates.data', 1)->where('templates.data.0.id', $template->id)
            ->has('documents.data', 1)->where('documents.data.0.id', $own->id));
        $this->get('https://north.company.test/company-documents/'.$foreign->id)->assertNotFound();
        $this->get('https://north.company.test/company-documents/'.$foreign->id.'/source')->assertNotFound();
    }
}
