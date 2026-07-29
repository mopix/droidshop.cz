<?php

namespace Tests\Unit\Core;

use App\Core\Settings\SettingsSchema;
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
}
