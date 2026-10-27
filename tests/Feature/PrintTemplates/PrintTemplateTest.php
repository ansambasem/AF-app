<?php

namespace Tests\Feature\PrintTemplates;

use App\Enums\PermissionKey;
use App\Models\Permission;
use App\Models\PrintTemplate;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintTemplateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function layout(array $overrides = []): array
    {
        return [
            'paper' => 'A4',
            'orientation' => 'landscape',
            'margin' => 10,
            'fontSize' => 12,
            'columns' => [
                ['key' => 'field:meterBoxName', 'label' => 'اسم الطبلون', 'visible' => true],
                ['key' => 'field:phone', 'label' => 'رقم الجوال', 'visible' => true],
            ],
            'table' => ['accent' => '#A51D26', 'zebra' => false],
            'header' => ['title' => 'القراءات حسب الطبلون', 'logo' => false],
            'group' => ['key' => 'field:meterBoxName', 'newPage' => true],
            'sort' => [['key' => 'field:phone', 'direction' => 'desc']],
            ...$overrides,
        ];
    }

    private function manager(): User
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->branchAdmin()->create();
        $user->permissions()->attach(Permission::where('key', PermissionKey::ManagePrintTemplates->value)->firstOrFail());

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.print-templates.index'))->assertRedirect(route('login'));
    }

    public function test_named_letterheads_keep_their_fonts_and_alignment_when_saved_and_copied(): void
    {
        $manager = $this->manager();
        $layout = $this->layout([
            'fontFamily' => 'alexandria', 'lineHeight' => 1.8,
            'header' => [
                'layout' => 'letterhead', 'company' => 'شركة أبو زايد',
                'companyEnglish' => "Company for General Trade\nFares Abu Zayed Sons",
                'companyDetails' => 'إدارة الشركة', 'title' => 'ورقة مقاصة',
                'logoPosition' => 'center', 'logoWidth' => 36, 'logoHeight' => 24,
                'gap' => 4, 'verticalAlign' => 'center', 'companyAlign' => 'right',
                'englishAlign' => 'left', 'titleAlign' => 'center',
                'companyFont' => 'plex', 'englishFont' => 'arial', 'titleFont' => 'messiri',
                'companySize' => 18, 'englishSize' => 14, 'titleSize' => 24, 'subtitleSize' => 12,
                'companyBold' => true, 'englishBold' => false, 'titleBold' => true,
                'ruleStyle' => 'double', 'ruleWidth' => 1, 'ruleColor' => '#A51D26',
            ],
        ]);

        foreach (['ورقة مقاصة', 'كشف تحصيل', 'كشف قراءات', 'تقرير مختصر', 'ترويسة الشركة', 'تقرير وسطي'] as $name) {
            $this->actingAs($manager)->post(route('print-templates.store'), [
                'page' => '/ledger', 'name' => $name, 'layout' => $layout,
            ])->assertSessionHasNoErrors();
        }

        $template = PrintTemplate::where('name', 'ورقة مقاصة')->sole();
        $this->assertSame($layout, $template->layout);
        $this->post(route('print-templates.duplicate', $template))->assertSessionHas('status', 'print-template-created');
        $this->assertSame($layout, PrintTemplate::where('name', 'ورقة مقاصة (نسخة)')->sole()->layout);
        $this->assertDatabaseCount('print_templates', 7);
    }

    public function test_invalid_letterhead_fonts_dimensions_and_colours_are_not_saved(): void
    {
        $this->actingAs($this->manager())->post(route('print-templates.store'), [
            'page' => '/ledger', 'name' => 'تصميم غير صالح',
            'layout' => $this->layout([
                'fontFamily' => 'remote-font', 'lineHeight' => 8,
                'header' => ['layout' => 'html', 'logoWidth' => 500, 'logoHeight' => 0,
                    'logoPosition' => 'absolute', 'titleSize' => 300, 'ruleColor' => 'red; background: url(x)'],
            ]),
        ])->assertSessionHasErrors([
            'layout.fontFamily', 'layout.lineHeight', 'layout.header.layout', 'layout.header.logoWidth',
            'layout.header.logoHeight', 'layout.header.logoPosition', 'layout.header.titleSize', 'layout.header.ruleColor',
        ]);

        $this->assertDatabaseCount('print_templates', 0);
    }

    public function test_a_user_without_the_permission_cannot_open_or_change_templates(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();
        $template = PrintTemplate::factory()->create();

        $this->actingAs($branchAdmin)->get(route('settings.print-templates.index'))->assertForbidden();
        $this->actingAs($branchAdmin)
            ->post(route('print-templates.store'), ['page' => '/meter-readings', 'name' => 'x', 'layout' => $this->layout()])
            ->assertForbidden();
        $this->actingAs($branchAdmin)->delete(route('print-templates.destroy', $template))->assertForbidden();

        $this->assertModelExists($template);
    }

    public function test_the_page_lists_every_template_under_its_list(): void
    {
        PrintTemplate::factory()->create(['page' => '/meter-readings', 'name' => 'حسب الطبلون', 'layout' => $this->layout()]);
        PrintTemplate::factory()->create(['page' => '/subscriptions', 'name' => 'أرقام الجوال']);

        $this->actingAs($this->manager())->get(route('settings.print-templates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/PrintTemplates')
                ->where('pages.0.path', '/meter-readings')
                ->where('pages.0.templates.0.name', 'حسب الطبلون')
                ->where('pages.0.templates.0.columnCount', 2)
                ->where('pages.0.templates.0.orientation', 'landscape')
                ->where('pages.1.path', '/subscriptions')
                ->where('pages.1.templates.0.name', 'أرقام الجوال'));
    }

    public function test_a_template_saved_as_default_replaces_the_lists_previous_default(): void
    {
        $manager = $this->manager();
        $previous = PrintTemplate::factory()->create(['page' => '/meter-readings', 'is_default' => true]);
        $otherList = PrintTemplate::factory()->create(['page' => '/subscriptions', 'is_default' => true]);

        $this->actingAs($manager)
            ->post(route('print-templates.store'), ['page' => '/meter-readings', 'name' => 'حسب الطبلون', 'layout' => $this->layout(), 'is_default' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'print-template-created');

        $template = PrintTemplate::where('name', 'حسب الطبلون')->sole();
        $this->assertTrue($template->is_default);
        $this->assertSame($this->layout(), $template->layout);
        $this->assertSame($manager->id, $template->updated_by);
        $this->assertFalse($previous->fresh()->is_default);
        $this->assertTrue($otherList->fresh()->is_default);
    }

    public function test_a_name_may_repeat_across_lists_but_not_within_one(): void
    {
        $manager = $this->manager();
        PrintTemplate::factory()->create(['page' => '/meter-readings', 'name' => 'شهري']);

        $this->actingAs($manager)
            ->post(route('print-templates.store'), ['page' => '/meter-readings', 'name' => 'شهري', 'layout' => $this->layout()])
            ->assertSessionHasErrors(['name' => 'يوجد قالب بهذا الاسم لهذه القائمة، اختر اسمًا آخر.']);
        $this->actingAs($manager)
            ->post(route('print-templates.store'), ['page' => '/subscriptions', 'name' => 'شهري', 'layout' => $this->layout()])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('print_templates', 2);
    }

    public function test_reading_templates_keep_blank_columns_widths_secondary_fields_and_nested_groups(): void
    {
        $manager = $this->manager();
        $layout = $this->layout([
            'columns' => [
                ['key' => 'field:fullName', 'label' => 'المشترك', 'secondaryKey' => 'field:phone', 'width' => 45, 'visible' => true],
                ['key' => 'blank:notes', 'label' => 'ملاحظات', 'blank' => true, 'width' => 30, 'visible' => true],
            ],
            'group' => ['key' => 'field:readerName', 'then' => ['field:subAreaName', 'field:boxNumber'], 'newPage' => true],
        ]);
        foreach (['/reading-cycles/print', '/paper-sheets'] as $page) {
            $this->actingAs($manager)->post(route('print-templates.store'), ['page' => $page, 'name' => 'كشف منظم', 'layout' => $layout])->assertSessionHasNoErrors();
            $this->assertSame($layout, PrintTemplate::where('page', $page)->sole()->layout);
        }
        $layout['columns'][0]['width'] = 121;
        $this->post(route('print-templates.store'), ['page' => '/reading-cycles/print', 'name' => 'عرض زائد', 'layout' => $layout])->assertSessionHasErrors('layout.columns.0.width');
        $this->assertDatabaseCount('print_templates', 2);
    }

    public function test_a_template_is_refused_for_an_unknown_list_or_an_unsafe_colour(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('print-templates.store'), ['page' => '/admin', 'name' => 'x', 'layout' => $this->layout()])
            ->assertSessionHasErrors(['page' => 'هذه الصفحة لا تدعم قوالب الطباعة.']);
        $this->actingAs($manager)
            ->post(route('print-templates.store'), ['page' => '/meter-readings', 'name' => 'x', 'layout' => $this->layout(['table' => ['accent' => 'red;background:url(x)']])])
            ->assertSessionHasErrors('layout.table.accent');

        $this->assertDatabaseCount('print_templates', 0);
    }

    public function test_a_template_is_renamed_redesigned_and_unset_as_default(): void
    {
        $template = PrintTemplate::factory()->create(['is_default' => true]);

        $this->actingAs($this->manager())
            ->put(route('print-templates.update', $template), ['name' => 'جديد', 'layout' => $this->layout(['paper' => 'A3']), 'is_default' => false])
            ->assertSessionHasNoErrors();

        $template->refresh();
        $this->assertSame('جديد', $template->name);
        $this->assertSame('A3', $template->layout['paper']);
        $this->assertFalse($template->is_default);
    }

    public function test_a_copy_takes_the_next_free_name_and_is_never_the_default(): void
    {
        $template = PrintTemplate::factory()->create(['name' => 'شهري', 'is_default' => true]);
        PrintTemplate::factory()->create(['name' => 'شهري (نسخة)']);

        $this->actingAs($this->manager())->post(route('print-templates.duplicate', $template))->assertSessionHas('status', 'print-template-created');

        $copy = PrintTemplate::where('name', 'شهري (نسخة 2)')->sole();
        $this->assertFalse($copy->is_default);
        $this->assertSame($template->layout, $copy->layout);
    }

    public function test_a_template_is_deleted(): void
    {
        $template = PrintTemplate::factory()->create();

        $this->actingAs($this->manager())->delete(route('print-templates.destroy', $template))->assertSessionHas('status', 'print-template-deleted');

        $this->assertModelMissing($template);
    }

    public function test_a_list_opened_for_printing_gets_its_templates_default_first(): void
    {
        $dataEntry = User::factory()->dataEntry()->create();
        PrintTemplate::factory()->create(['page' => '/meter-readings', 'name' => 'أ']);
        PrintTemplate::factory()->create(['page' => '/meter-readings', 'name' => 'ي', 'is_default' => true]);
        PrintTemplate::factory()->create(['page' => '/subscriptions', 'name' => 'مشتركون']);

        $this->actingAs($dataEntry)->get(route('meter-readings.index', ['print' => 1]))
            ->assertInertia(fn ($page) => $page
                ->has('printTemplates', 2)
                ->where('printTemplates.0.name', 'ي')
                ->where('printTemplates.0.isDefault', true));
        $this->actingAs($dataEntry)->get(route('meter-readings.index'))
            ->assertInertia(fn ($page) => $page->where('printTemplates', null));
    }

    public function test_financial_report_printing_uses_its_shared_company_templates(): void
    {
        PrintTemplate::factory()->create(['page' => '/reports', 'name' => 'تقرير الإدارة', 'is_default' => true]);
        PrintTemplate::factory()->create(['page' => '/meter-readings', 'name' => 'قراءات']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('reports.index', ['print' => 1, 'print_view' => 'summary']))
            ->assertInertia(fn ($page) => $page
                ->has('printTemplates', 1)
                ->where('printTemplates.0.name', 'تقرير الإدارة')
                ->where('printTemplates.0.isDefault', true));
    }
}
