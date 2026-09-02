# Third-party notices

This package redistributes prebuilt `curl-impersonate` executables and shared
libraries under `bin/`, downloaded unmodified (then stripped of debug symbols
on Linux; see `bin/UPSTREAM-CHECKSUMS`) from the release assets of
[lexiforest/curl-impersonate](https://github.com/lexiforest/curl-impersonate).
The version is recorded in `bin/VERSION`. The PHP code in `src/` is MIT
licensed (see `LICENSE.md`); the binaries are subject to the licences of the
projects statically linked into them, listed below. Each project's full
licence text is available at the linked repository, and the exact build
recipe — including the source of every component at the version used — is
the upstream repository's `Makefile.in` and `Dockerfile`s at the tag in
`bin/VERSION`.

| Component | Licence | Source |
|---|---|---|
| curl-impersonate (patches, build) | MIT | https://github.com/lexiforest/curl-impersonate |
| curl / libcurl | curl licence (MIT/X derivative) | https://curl.se/docs/copyright.html |
| BoringSSL | ISC, OpenSSL and SSLeay licences (mixed, per file) | https://boringssl.googlesource.com/boringssl |
| nghttp2 | MIT | https://github.com/nghttp2/nghttp2 |
| ngtcp2 | MIT | https://github.com/ngtcp2/ngtcp2 |
| nghttp3 | MIT | https://github.com/ngtcp2/nghttp3 |
| zlib | zlib licence | https://zlib.net |
| Brotli | MIT | https://github.com/google/brotli |
| Zstandard | BSD-3-Clause (and GPL-2.0 dual) | https://github.com/facebook/zstd |
| libidn2 | LGPL-3.0-or-later (or GPL-2.0-or-later) | https://gitlab.com/libidn/libidn2 |
| libunistring (via libidn2) | LGPL-3.0-or-later (or GPL-2.0-or-later) | https://www.gnu.org/software/libunistring/ |

## Note on LGPL components

`libidn2` (and `libunistring`, which it depends on) is statically linked into
the bundled binaries by the upstream build. The LGPL permits this on the
condition that recipients can relink the work against a modified version of
the library: upstream satisfies it by publishing the complete build recipe
and the corresponding source versions at the release tag, and this package
preserves that by pinning the tag in `bin/VERSION` and by recording the
sha256 of every upstream asset it was derived from in `bin/UPSTREAM-CHECKSUMS`,
so that any bundled file can be reproduced from upstream's release. If your
distribution policy does not permit statically linked LGPL code, build
`curl-impersonate` yourself with `--without-libidn2` and point this package
at it with `PHP_IMPERSONATE_LIB` (FFI engine) or by placing the executable in
`bin/<platform>/` (executable engine).

## Trademarks

Chrome, Firefox, Safari, Edge and Tor Browser are trademarks of their
respective owners. The profiles in this package reproduce the network
behaviour of those browsers and imply no affiliation or endorsement.
