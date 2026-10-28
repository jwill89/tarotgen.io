<?php

/**
 * The backend's one code-style tool: `composer lint` checks, `composer lint:fix`
 * fixes, and CI runs the check (AUDIT §5.2).
 *
 * PER Coding Style 3.0, PSR-12's successor, pinned so a fixer upgrade can't move
 * the standard under us. Where PER-CS and this codebase disagree on something
 * cosmetic, the codebase's long-standing habit wins, so adopting the tool didn't
 * restyle working code.
 */

return new PhpCsFixer\Config()
    ->setRules([
        '@PER-CS3x0' => true,
        '@PHP8x5Migration' => true,
        // (int)$x, as in ~340 casts here.
        'cast_spaces' => ['space' => 'none'],
        // An empty body (mostly promoted-property constructors) keeps its own lines.
        'single_line_empty_body' => false,
        // Multi-line arrays and parameter lists end with a comma; call arguments don't.
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'array_destructuring', 'match', 'parameters']],
        'no_unused_imports' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/api', __DIR__ . '/bin', __DIR__ . '/includes', __DIR__ . '/scripts', __DIR__ . '/tests'])
            ->append([__DIR__ . '/og.php', __DIR__ . '/dev-router.php', __FILE__])
    )
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
