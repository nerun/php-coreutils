<div align="center">

<h1>PHP Coreutils</h1>

<img src="https://img.shields.io/badge/MIT-License-blue?style=flat-square&logo=open-source-initiative&logoColor=white" alt="MIT License">

<img src="https://img.shields.io/badge/PHP-Language-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP Language">

<img src="https://img.shields.io/badge/warning-experimental-yellow?style=flat-square&logo=data:image/svg%2bxml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+CiAgICA8cGF0aCBmaWxsPSJ3aGl0ZSIgZD0iTTEgMjFoMjJMMTIgMiAxIDIxeiI+PC9wYXRoPgogICAgPHJlY3QgeD0iMTEiIHk9IjgiIHdpZHRoPSIyIiBoZWlnaHQ9IjYiIGZpbGw9ImJsYWNrIj48L3JlY3Q+CiAgICA8cmVjdCB4PSIxMSIgeT0iMTYiIHdpZHRoPSIyIiBoZWlnaHQ9IjIiIGZpbGw9ImJsYWNrIj48L3JlY3Q+Cjwvc3ZnPg==">

<p><i>A lightweight, pure-PHP implementation of classic Unix core utilities.</i></p>

</div>

## Overview

PHP Coreutils aims to provide a familiar set of Unix-like tools implemented
entirely in PHP, without relying on shell execution (`exec`, `shell_exec`,
etc.). The goal is simplicity, portability, and usability in environments where
shell access is limited or unavailable.

This project is inspired by the philosophy of traditional Unix utilities:

* Do one thing well
* Keep interfaces simple
* Allow composition of small tools

## Motivation

In many environments—such as shared hosting or web-based file managers—access
to system-level utilities is restricted. PHP Coreutils fills that gap by
offering a minimal, self-contained toolkit for file and text manipulation using
pure PHP.

## Features

* Pure PHP implementation (no external dependencies required)
* Unix-like command semantics
* Designed for portability across environments
* Safe for restricted or sandboxed execution contexts

## Example

```php
$data = cat("file.txt");
$data = grep("ERROR", $data);
$count = wc($data);
```

## Philosophy

This project does not attempt to fully replicate a Unix shell. Instead, it
provides function-based equivalents of common utilities, allowing developers to
compose operations programmatically.

Pipelines are represented through function composition rather than shell pipes:

```php
$count = wc(
    grep("ERROR",
        cat("file.txt")
    )
);
```

## Scope

The project focuses on core utilities such as:

* File operations (`ls`, `cp`, `mv`, `rm`)
* Text processing (`cat`, `grep`, `head`, `tail`, `wc`)
* Basic utilities (`echo`, `pwd`, etc.)

The goal is not to achieve full parity with system implementations, but to
provide practical and predictable behavior.

## Installation

Clone the repository:

```shell
git clone https://github.com/nerun/php-coreutils.git
```

Include it in your project as needed.

## Usage

Functions are designed to be simple and composable. Each function:

* Accepts standard PHP data types (e.g., strings or arrays)
* Returns data suitable for further processing

Refer to the source code for available functions and usage patterns.

## Limitations

* Not a replacement for a real shell environment
* May load entire files into memory depending on implementation
* Behavior may differ from native Unix utilities in edge cases

## Contributing

Contributions are welcome. Keep changes:

* Small
* Focused
* Consistent with the project philosophy
