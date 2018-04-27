# Changelog

## 0.2.0 - 2012-03-21

* Tests for Txp tag content akin to chh_if_data -- added `smd_wrap_all` tag to emulate v0.10 behaviour (thanks maniqui).
* Added `form` attribute and `<txp:yield />` support (thanks maniqui).
* Added `<txp:smd_wrap_info />` instead of `{smd_wrap_it}`: strongly suggest it is used unless you know what you're doing (thanks Gocom).
* Added `time` as a synonym for `date` since they run the same transform.
* Added `raw` and `encode` sanitization transforms, and the `cut` transform (all thanks maniqui).
* Added `currency`, `round` and `number` transforms (thanks jakob, milosevic).
* Fixed lost trailing space issues in transforms (thanks maniqui).

## 0.1.0 - 2011-11-22

* Initial release
