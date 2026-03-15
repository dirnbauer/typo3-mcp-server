.. include:: /Includes.rst.txt

================
Inline relations
================

Overview
========

TYPO3 inline relations, also known as IRRE, allow parent-child record
structures. The MCP tools support reading and writing inline relations with a
workspace-aware model and a few deliberate limitations.

Relation types
==============

Independent inline relations
----------------------------

These relations point to records that also exist independently, for example
``tt_content``. In read results they are usually represented as arrays of UIDs.

Embedded inline relations
-------------------------

These relations point to child records that primarily exist under a parent, for
example some dependent extension tables. In read results they are typically
returned as embedded record arrays.

Current write support
=====================

Independent relations
---------------------

Independent relations can be handled in two useful ways:

- Update the child record's foreign field directly
- Update the parent record with an array of related child UIDs

Embedded relations
------------------

Embedded child creation works through a two-step approach:

- ``DataHandler`` creates the child records
- The extension applies the necessary foreign-field update afterwards when TYPO3
  does not manage that relation field directly through TCA columns

File references
===============

``sys_file_reference`` records are now supported through the ``WriteTable``
tool. When a file field (TCA type ``file``) receives an array of
``sys_file`` UIDs or objects with UID and metadata, the extension creates
``sys_file_reference`` records and wires them to the parent record through
``DataHandler``. File references are workspace-versioned like other records.

Automatic workspace handling
============================

Inline relation reads and writes use the same workspace context service as the
rest of the extension:

- A writable workspace is selected automatically, or created when needed
- Live IDs stay visible to clients
- Workspace-specific implementation details stay internal

Best practices
==============

- Prefer the foreign-field method for simple independent relations
- Check TCA configuration to understand whether a relation is independent or
  embedded
- Validate embedded payloads carefully
- Use TYPO3 error output from ``DataHandler`` when diagnosing relation failures

Future improvements
===================

- Bulk updates for relation management
- Better ordering and positioning support
- Stronger validation of embedded record payloads
