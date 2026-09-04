# Third-party notices

This package redistributes prebuilt `curl-impersonate` executables and shared
libraries under `bin/`, downloaded unmodified (then stripped of debug symbols
on Linux; see `bin/UPSTREAM-CHECKSUMS`) from the release assets of
[lexiforest/curl-impersonate](https://github.com/lexiforest/curl-impersonate).
The version is recorded in `bin/VERSION`. The PHP code in `src/` is MIT
licensed (see `LICENSE.md`); the binaries are subject to the licences of the
projects statically linked into them, listed below. The verbatim licence and
copyright notice of each is reproduced under `THIRD_PARTY_LICENSES/` and ships
with the package, as the MIT, BSD and zlib licences require of a binary
redistribution; the exact build recipe — including the source of every
component at the version used — is the upstream repository's `Makefile.in`
and `Dockerfile`s at the tag in `bin/VERSION`.

| Component | Licence | Licence text | Source |
|---|---|---|---|
| curl-impersonate (patches, build) | MIT | [curl-impersonate.txt](THIRD_PARTY_LICENSES/curl-impersonate.txt) | https://github.com/lexiforest/curl-impersonate |
| curl / libcurl | curl licence (MIT/X derivative) | [curl.txt](THIRD_PARTY_LICENSES/curl.txt) | https://curl.se/docs/copyright.html |
| BoringSSL | Apache-2.0, with the ISC, OpenSSL and SSLeay notices retained for older files | [boringssl.txt](THIRD_PARTY_LICENSES/boringssl.txt) | https://boringssl.googlesource.com/boringssl |
| nghttp2 | MIT | [nghttp2.txt](THIRD_PARTY_LICENSES/nghttp2.txt) | https://github.com/nghttp2/nghttp2 |
| ngtcp2 | MIT | [ngtcp2.txt](THIRD_PARTY_LICENSES/ngtcp2.txt) | https://github.com/ngtcp2/ngtcp2 |
| nghttp3 | MIT | [nghttp3.txt](THIRD_PARTY_LICENSES/nghttp3.txt) | https://github.com/ngtcp2/nghttp3 |
| zlib | zlib licence | [zlib.txt](THIRD_PARTY_LICENSES/zlib.txt) | https://zlib.net |
| Brotli | MIT | [brotli.txt](THIRD_PARTY_LICENSES/brotli.txt) | https://github.com/google/brotli |
| Zstandard | BSD-3-Clause (and GPL-2.0 dual) | [zstd.txt](THIRD_PARTY_LICENSES/zstd.txt) | https://github.com/facebook/zstd |
| libidn2 | LGPL-3.0-or-later (or GPL-2.0-or-later) | [libidn2-COPYING.txt](THIRD_PARTY_LICENSES/libidn2-COPYING.txt), [LGPLv3](THIRD_PARTY_LICENSES/libidn2-COPYING.LESSERv3.txt), [GPLv2](THIRD_PARTY_LICENSES/libidn2-COPYINGv2.txt) | https://gitlab.com/libidn/libidn2 |
| libunistring (via libidn2) | LGPL-3.0-or-later (or GPL-2.0-or-later) | [libunistring-COPYING.LIB.txt](THIRD_PARTY_LICENSES/libunistring-COPYING.LIB.txt) | https://www.gnu.org/software/libunistring/ |

The texts were taken from each project's repository at the version the
bundled binaries report (`curl-impersonate --version`: curl 8.21.0, nghttp2
1.63.0, ngtcp2 1.20.0, nghttp3 1.15.0, zlib 1.3.1, brotli 1.2.0, zstd 1.5.7,
libidn2 2.3.7), and from the current tree where a project does not tag its
licence file with a version (curl-impersonate v2.1.1, BoringSSL, libunistring).

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
