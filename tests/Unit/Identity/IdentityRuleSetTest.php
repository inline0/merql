<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Identity;

use Merql\Identity\IdentityRule;
use Merql\Identity\IdentityRuleSet;
use Merql\Schema\TableSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityRuleSetTest extends TestCase
{
    #[Test]
    public function resolves_explicit_rule_before_schema_default(): void
    {
        $rules = new IdentityRuleSet([
            'options' => IdentityRule::natural(['option_name']),
        ]);
        $schema = new TableSchema('options', ['option_id' => 'int', 'option_name' => 'text'], ['option_id']);

        $this->assertSame(['option_name'], $rules->ruleFor($schema)->columns);
        $this->assertSame(IdentityRule::TYPE_NATURAL, $rules->ruleFor($schema)->type);
    }

    #[Test]
    public function detects_ambiguous_identity_matches(): void
    {
        $rule = IdentityRule::natural(['post_id', 'meta_key']);
        $conflicts = (new IdentityRuleSet())->conflictsFor('postmeta', $rule, [
            ['meta_id' => '1', 'post_id' => '10', 'meta_key' => 'color'],
            ['meta_id' => '2', 'post_id' => '10', 'meta_key' => 'color'],
        ]);

        $this->assertCount(1, $conflicts);
        $this->assertSame('postmeta', $conflicts[0]->table);
        $this->assertSame(2, $conflicts[0]->matchCount);
    }

    #[Test]
    public function serializes_and_hydrates_rules(): void
    {
        $rules = new IdentityRuleSet(['options' => IdentityRule::natural(['option_name'])]);
        $hydrated = IdentityRuleSet::fromArray($rules->toArray());
        $schema = new TableSchema('options', ['option_name' => 'text'], []);

        $this->assertSame(['option_name'], $hydrated->ruleFor($schema)->columns);
    }

    #[Test]
    public function resolves_schema_defaults_and_identity_types(): void
    {
        $primary = IdentityRule::forSchema(new TableSchema('posts', ['id' => 'int'], ['id']));
        $unique = IdentityRule::forSchema(new TableSchema('options', ['name' => 'text'], [], [['name']]));
        $content = IdentityRule::forSchema(new TableSchema('logs', ['message' => 'text'], []));

        $this->assertSame(IdentityRule::TYPE_PRIMARY, $primary->type);
        $this->assertSame(IdentityRule::TYPE_NATURAL, $unique->type);
        $this->assertSame(IdentityRule::TYPE_CONTENT, $content->type);
        $this->assertSame('1', IdentityRule::primary(['id'])->key(['id' => '1']));
        $this->assertSame('a', IdentityRule::composite(['slug'])->key(['slug' => 'a']));
        $this->assertNotSame('', IdentityRule::content(['message'])->key(['message' => 'hello']));
        $this->assertNotSame('', (new IdentityRule('unknown', ['message']))->key(['message' => 'hello']));
    }

    #[Test]
    public function normalizes_invalid_rule_arrays(): void
    {
        $rule = IdentityRule::fromArray(['type' => 123, 'columns' => ['id', 1]]);
        $emptyRule = IdentityRule::fromArray(['columns' => 'bad']);
        $rules = IdentityRuleSet::fromArray(['posts' => ['type' => 'primary', 'columns' => ['id']], 1 => 'bad']);
        $withRule = (new IdentityRuleSet())->with('custom', IdentityRule::natural(['slug']));

        $this->assertSame(IdentityRule::TYPE_CONTENT, $rule->type);
        $this->assertSame(['id'], $rule->columns);
        $this->assertSame([], $emptyRule->columns);
        $this->assertSame(['id'], $rules->ruleFor(new TableSchema('posts', ['id' => 'int'], []))->columns);
        $fallbackRule = (new IdentityRuleSet())->ruleFor(new TableSchema('fallback', ['id' => 'int'], ['id']));
        $this->assertSame(['id'], $fallbackRule->columns);
        $this->assertSame(['slug'], $withRule->ruleFor(new TableSchema('custom', ['slug' => 'text'], []))->columns);
    }
}
