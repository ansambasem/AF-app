<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\ExcelTemplate;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The company's Excel templates, as the Excel editor reads and saves them:
 * anyone may use them, and whoever manages the print templates may add,
 * change, remove and choose the default one.
 */
class ExcelTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function additionalExportKinds(): array
    {
        return ['site tables' => ['tables'], 'statements' => ['statements'], 'reading history' => ['reading-history']];
    }

    #[DataProvider('additionalExportKinds')]
    public function test_each_new_export_can_save_and_retrieve_its_own_company_template(string $kind): void
    {
        ExcelTemplate::factory()->create(['kind' => 'reports', 'is_default' => true]);
        $this->actingAs($this->manager());

        $this->postJson(route('excel-templates.store'), ['kind' => $kind, 'name' => 'تصميم للطباعة', 'is_default' => true, 'settings' => ['theme' => 'plain']])
            ->assertCreated()->assertJsonPath('template.isDefault', true);

        $this->getJson(route('excel-templates.index', $kind))->assertJsonCount(1, 'templates')
            ->assertJsonPath('templates.0.name', 'تصميم للطباعة')->assertJsonPath('templates.0.settings.theme', 'plain');
        $this->assertDatabaseHas('excel_templates', ['kind' => $kind, 'name' => 'تصميم للطباعة', 'is_default' => true]);
        $this->assertDatabaseHas('excel_templates', ['kind' => 'reports', 'is_default' => true]);
    }

    public function test_the_editor_lists_a_kinds_templates_default_first_and_says_who_may_change_them(): void
    {
        ExcelTemplate::factory()->create(['name' => 'أ نسخة الفروع']);
        ExcelTemplate::factory()->create(['name' => 'ب نسخة المحاسب', 'is_default' => true]);

        $this->actingAs($this->manager())->getJson(route('excel-templates.index', 'reports'))
            ->assertOk()
            ->assertJsonPath('canManage', true)
            ->assertJsonPath('templates.0.name', 'ب نسخة المحاسب')
            ->assertJsonPath('templates.0.isDefault', true)
            ->assertJsonPath('templates.1.name', 'أ نسخة الفروع');
        $this->actingAs(User::factory()->accountant()->create())->getJson(route('excel-templates.index', 'reports'))
            ->assertOk()
            ->assertJsonPath('canManage', false)
            ->assertJsonCount(2, 'templates');
        $this->getJson(route('excel-templates.index', 'unknown'))->assertUnprocessable();
    }

    public function test_a_saved_template_keeps_the_layout_only_and_becomes_the_only_default(): void
    {
        $previous = ExcelTemplate::factory()->create(['is_default' => true]);

        $response = $this->actingAs($this->manager())->postJson(route('excel-templates.store'), [
            'kind' => 'reports',
            'name' => 'نسخة المحاسب',
            'is_default' => true,
            'settings' => [
                'title' => 'لا يُحفظ',
                'fileName' => 'لا يُحفظ',
                'theme' => 'blue',
                'zebra' => false,
                'unknown' => 'x',
                'parts' => ['lines' => ['on' => true, 'groupBy' => 'recordedBy', 'columns' => [['key' => 'day', 'on' => true, 'width' => 20], ['key' => 'time', 'on' => false]]]],
            ],
        ])->assertCreated()->assertJsonPath('template.isDefault', true);

        $template = ExcelTemplate::findOrFail($response->json('template.id'));
        $this->assertSame([
            'zebra' => false,
            'theme' => 'blue',
            'parts' => ['lines' => ['on' => true, 'groupBy' => 'recordedBy', 'columns' => [['key' => 'day', 'on' => true, 'width' => 20], ['key' => 'time', 'on' => false]]]],
        ], $template->settings);
        $this->assertFalse($previous->refresh()->is_default);
    }

    public function test_a_template_is_renamed_unset_as_default_and_removed(): void
    {
        $template = ExcelTemplate::factory()->create(['name' => 'قديم', 'is_default' => true]);
        $this->actingAs($this->manager());

        $this->putJson(route('excel-templates.update', $template), ['name' => 'جديد', 'is_default' => false])
            ->assertOk()
            ->assertJsonPath('template.name', 'جديد')
            ->assertJsonPath('template.isDefault', false);
        $this->deleteJson(route('excel-templates.destroy', $template))->assertOk();

        $this->assertModelMissing($template);
    }

    public function test_only_whoever_manages_the_templates_may_change_them(): void
    {
        $template = ExcelTemplate::factory()->create();
        $this->actingAs(User::factory()->accountant()->create());

        $this->postJson(route('excel-templates.store'), ['kind' => 'reports', 'name' => 'x', 'settings' => ['zebra' => true]])->assertForbidden();
        $this->putJson(route('excel-templates.update', $template), ['name' => 'y'])->assertForbidden();
        $this->deleteJson(route('excel-templates.destroy', $template))->assertForbidden();

        $this->assertModelExists($template);
        $this->assertSame(1, ExcelTemplate::count());
    }

    public function test_a_name_is_unique_within_its_kind_and_the_choices_are_the_editors(): void
    {
        ExcelTemplate::factory()->create(['name' => 'نسخة المحاسب']);
        $this->actingAs($this->manager());

        $this->postJson(route('excel-templates.store'), ['kind' => 'reports', 'name' => 'نسخة المحاسب', 'settings' => ['zebra' => true]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'يوجد قالب بهذا الاسم، اختر اسمًا آخر.']);
        $this->postJson(route('excel-templates.store'), [
            'kind' => 'reports',
            'name' => 'أخرى',
            'settings' => ['theme' => 'pink', 'parts' => ['lines' => ['columns' => [['key' => 'day', 'width' => 500]]]]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['settings.theme', 'settings.parts.lines.columns.0.width']);
        $this->postJson(route('excel-templates.store'), [])->assertUnprocessable()->assertJsonValidationErrors(['kind', 'name', 'settings']);
    }

    private function manager(): User
    {
        $user = User::factory()->accountant()->create();
        $user->permissions()->sync(Permission::idsFor([PermissionKey::ManagePrintTemplates]));

        return $user;
    }
}
