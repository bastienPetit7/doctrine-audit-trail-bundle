# Contributing

Every contribution is appreciated — whether it's a typo fix, improved documentation, a bug fix, or a new feature. Thank you for helping improve `doctrine-audit-trail-bundle`.

This project is maintained with kindness and respect. Don't hesitate to contribute — every pull request is reviewed with good intentions and constructive feedback. There are no silly questions or too-small contributions.

## Reporting bugs

If you think you have found a bug, please [open an issue](../../issues/new) with:

- A precise description of the bug and steps to reproduce it.
- PHP, Symfony, and Doctrine ORM versions used.
- Bundle version used.
- If possible, a minimal reproducing test case.

## Requesting features

Before opening a feature request, search existing issues to avoid duplicates. Describe the use case as precisely as possible and link any relevant resources.

## Development setup

Fork the repository, clone it and create a new branch:

No Docker or external database is required — integration tests use an in-memory SQLite entity manager.

## Running the CI pipeline locally

```bash
# Full CI (code style + static analysis + tests) — run this before pushing
composer ci

# Individual checks
composer cs-check          # PHP CS Fixer (dry-run)
composer cs-fix            # PHP CS Fixer (apply fixes)
composer phpstan           # PHPStan level 8
composer test              # All tests
composer test-unit         # Unit tests only
composer test-integration  # Integration tests only

# Single test file
vendor/bin/phpunit tests/Unit/Diff/ChangeSetExtractorTest.php
```

## Coding standards

This project follows [Symfony coding standards](https://symfony.com/doc/current/contributing/code/standards.html) (PSR-12 superset) enforced by PHP CS Fixer — see `.php-cs-fixer.dist.php` for the full rule set. Code must also pass **PHPStan level 8** with no baseline entries.

## Writing tests

- **Unit tests** go in `tests/Unit/`, **integration tests** in `tests/Integration/`.
- One test = one behavior, not one method.
- Test naming convention: `it_should_[verb]_when_[condition]`.
- Use test fixtures from `tests/Fixtures/` (`AuditedProduct`, `PlainCategory`, …).
- Integration tests use `InMemoryAuditEntityManagerTrait` for throwaway SQLite entity managers.
- No hardcoded inline data — use factories or fixture classes.

## Making your changes

1. Create a branch from `main`: `feature/…`, `fix/…`, or `refactor/…`.
2. Make small, logical commits — don't mix unrelated changes.
3. Follow [Conventional Commits](https://www.conventionalcommits.org/):
   - `feat:` new feature
   - `fix:` bug fix
   - `refactor:` code change that neither fixes a bug nor adds a feature
   - `test:` adding or updating tests
   - `docs:` documentation only
   - `chore:` maintenance tasks
4. Ensure `composer ci` passes before pushing.

## Submitting a pull request

1. Push your branch to your fork.
2. [Open a pull request](../../pulls) against the `main` branch.
3. Describe your changes concisely but with enough detail for reviewers.
4. One PR = one responsibility. Keep it focused.
5. If your branch conflicts with `main`, rebase before requesting review:

   ```bash
   git fetch upstream
   git rebase upstream/main
   git push origin feature/short-description --force-with-lease
   ```

6. If you need to squash commits before merging:

   ```bash
   git rebase -i upstream/main
   ```

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
