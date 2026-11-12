db-privilege is a runtime check: it queries SHOW GRANTS as the application
account. There is no file to violate, so these two trees stay empty. The
self-test asserts the unconfigured path skips loudly instead of failing the
build; ../../README.md shows how to prove the live path both ways.
