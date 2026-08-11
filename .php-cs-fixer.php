<?php

declare(strict_types=1);

// Baseline formatting pass (project audit, Tier 1) — @PSR12 plus a few
// low-risk, non-controversial extras. Deliberately not aggressive: this
// runs automatically after every Write/Edit (see .claude/settings.json's
// PostToolUse hook), so a rule that reformats large swaths of already-
// correct code on first run would be disruptive. Widen it later if wanted.
$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor', 'node_modules', 'data', 'cache', 'logs', 'sessions', 'public/files', 'public/temp'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        // This project's own convention has no blank line between `<?php`
        // and `declare(strict_types=1);` — PSR12's default wants one.
        'blank_line_after_opening_tag' => false,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'no_trailing_whitespace' => true,
        'single_blank_line_at_eof' => true,
        'blank_line_after_namespace' => true,
    ])
    ->setFinder($finder);
