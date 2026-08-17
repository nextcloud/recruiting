# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build a release tarball for the Nextcloud App Store:
#
#   make appstore
#
# The frontend must be built first (npm ci && npm run build) — the tarball
# ships js/ and never src/.

app_name=recruiting
version=$(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
build_dir=$(CURDIR)/build
staging_dir=$(build_dir)/appstore
package=$(build_dir)/$(app_name)-$(version).tar.gz
cert_dir=$(HOME)/.nextcloud/certificates
occ=$(CURDIR)/../../occ

.PHONY: all build test lint appstore clean

all: build

# Frontend bundles
build:
	npm ci
	npm run build

test:
	composer run test:unit

lint:
	composer run lint
	composer run cs:check
	composer run psalm
	npm run lint
	npm run stylelint

# Re-render docs/handbook.pdf after UI or screenshot changes
handbook:
	cd docs && "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
		--headless --disable-gpu --no-pdf-header-footer \
		--print-to-pdf=handbook.pdf handbook.html

appstore: clean
	mkdir -p $(staging_dir)/$(app_name)
	rsync -a \
		--exclude=/.git \
		--exclude=/.github \
		--exclude=/.gitignore \
		--exclude=/.php-cs-fixer.cache \
		--exclude=/.php-cs-fixer.dist.php \
		--exclude=/build \
		--exclude=/node_modules \
		--exclude=/src \
		--exclude=/tests \
		--exclude=/vendor \
		--exclude=/vendor-bin \
		--exclude=/screenshots \
		--exclude=/docs/handbook.html \
		--exclude=/docs/img \
		--exclude=/composer.json \
		--exclude=/composer.lock \
		--exclude=/eslint.config.mjs \
		--exclude=/stylelint.config.cjs \
		--exclude=/package.json \
		--exclude=/package-lock.json \
		--exclude=/psalm.xml \
		--exclude=/vite.config.js \
		--exclude=/Makefile \
		--exclude=/Recruiting-SPEC.md \
		$(CURDIR)/ $(staging_dir)/$(app_name)/
	@if [ -f $(cert_dir)/$(app_name).key ] && [ -f $(occ) ]; then \
		php $(occ) integrity:sign-app \
			--privateKey=$(cert_dir)/$(app_name).key \
			--certificate=$(cert_dir)/$(app_name).crt \
			--path=$(staging_dir)/$(app_name); \
	else \
		echo "No signing material in $(cert_dir) — building an unsigned tarball."; \
	fi
	tar -czf $(package) -C $(staging_dir) $(app_name)
	@echo "Built $(package)"
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Tarball signature for the App Store upload form:"; \
		openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(package) | openssl base64 -A; \
		echo; \
	fi

clean:
	rm -rf $(build_dir)
