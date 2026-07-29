<?php

namespace Tests\Unit\Core;

use App\Core\Settings\SettingsSchema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SettingsSchemaTest extends TestCase
{
    public function test_a_plain_string_is_read_as_rules_only(): void
    {
        $schema = SettingsSchema::fromArray(['due_days' => 'integer|min:0']);

        $field = $schema->field('due_days');

        $this->assertSame('integer|min:0', $field->rules);
        $this->assertSame('due_days', $field->label);
        $this->assertSame('number', $field->type);
        $this->assertNull($field->default);
    }

    public function test_an_object_carries_label_type_and_default(): void
    {
        $schema = SettingsSchema::fromArray([
            'auto_issue_on' => [
                'rules' => 'in:paid,shipped,manual',
                'label' => 'Faktura se vystaví',
                'type' => 'select',
                'default' => 'paid',
                'options' => ['paid' => 'Při zaplacení'],
            ],
        ]);

        $field = $schema->field('auto_issue_on');

        $this->assertSame('Faktura se vystaví', $field->label);
        $this->assertSame('select', $field->type);
        $this->assertSame('paid', $field->default);
        $this->assertSame(['paid' => 'Při zaplacení'], $field->options);
    }

    public function test_the_type_is_derived_from_the_rules_when_absent(): void
    {
        $schema = SettingsSchema::fromArray([
            'email_invoice' => 'boolean',
            'mode' => 'in:a,b',
            'note' => 'nullable|string|max:2000',
        ]);

        $this->assertSame('boolean', $schema->field('email_invoice')->type);
        $this->assertSame('select', $schema->field('mode')->type);
        $this->assertSame('textarea', $schema->field('note')->type);
    }

    public function test_rules_and_defaults_come_out_keyed_by_field(): void
    {
        $schema = SettingsSchema::fromArray([
            'a' => ['rules' => 'integer', 'default' => 3],
            'b' => 'boolean',
        ]);

        $this->assertSame(['a' => 'integer', 'b' => 'boolean'], $schema->rules());
        $this->assertSame(['a' => 3], $schema->defaults());
    }

    public function test_an_unknown_key_has_no_field(): void
    {
        $schema = SettingsSchema::fromArray(['a' => 'integer']);

        $this->assertFalse($schema->has('b'));
        $this->assertNull($schema->field('b'));
    }

    public function test_defaults_keeps_falsy_values_and_drops_only_null(): void
    {
        $schema = SettingsSchema::fromArray([
            'email_invoice' => ['rules' => 'boolean', 'default' => false],
            'min_order_total' => ['rules' => 'integer|min:0', 'default' => 0],
            'note' => ['rules' => 'nullable|string', 'default' => null],
        ]);

        $this->assertSame(
            ['email_invoice' => false, 'min_order_total' => 0],
            $schema->defaults(),
        );
    }

    public function test_a_list_of_rule_strings_is_joined_with_pipes(): void
    {
        // Ordinary Laravel convention — ['integer', 'min:0'] is what
        // Validator::make() accepts natively — must not silently no-op.
        $schema = SettingsSchema::fromArray(['due_days' => ['integer', 'min:0']]);

        $this->assertSame('integer|min:0', $schema->field('due_days')->rules);
    }

    public function test_an_object_with_no_rules_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Settings field [due_days] declares no validation rules.');

        SettingsSchema::fromArray(['due_days' => ['label' => 'Splatnost']]);
    }

    public function test_an_explicitly_empty_rules_string_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Settings field [due_days] declares no validation rules.');

        SettingsSchema::fromArray(['due_days' => ['rules' => '']]);
    }
}
