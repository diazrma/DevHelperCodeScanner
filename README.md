# DevHelper_CodeScanner

![Magento 2](https://img.shields.io/badge/Magento-2.x-brightgreen.svg)

## Overview

DevHelper_CodeScanner is a Magento 2 module that helps developers identify bad practices and common issues in custom modules. It provides a CLI command to scan your `app/code` directory and reports potential problems, making your codebase safer and more maintainable.

### What does it detect?
- Direct usage of `ObjectManager`
- Direct instantiation with `new` (except in tests)
- Blocks in XML without `class` or `template`
- Observers listening to generic events (e.g., `controller_action_predispatch`)
- Plugin `before`, `after`, and `around` methods without `return`
- Usage of dangerous PHP functions (`eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`)
- Debug functions left in code (`var_dump`, `print_r`, `die`, `exit`)

## Installation

1. Copy the module to `app/code/DevHelper/CodeScanner` in your Magento 2 project.
2. Run the following commands in your project root:

```bash
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

3. (Optional) Enable the module explicitly:
```bash
php bin/magento module:enable DevHelper_CodeScanner
```

## Usage

To scan your custom modules, run:

```bash
php bin/magento devhelper:codescan
```

The command will output a table with the type of issue, module, file, and line number (if available).

## Requirements
- Magento 2.4.x
- PHP 8.1+

## License

MIT or as you prefer.

---

**Maintainer:** Rodrigo Cardoso 