.. include:: /Includes.rst.txt

.. _configuration-live-edits-development:

=====================================================
Live edits on your development site (important change)
=====================================================

This page explains a **major change** in how the MCP server handles content
changes on **local development sites** (typically DDEV on your laptop). It is
written for editors, project owners, and integrators who are **not** deep into
TYPO3 internals.

If you only run MCP against a **live production website**, you can stop after
the next section — **nothing changed for you**.

.. contents::
   :local:
   :depth: 2

Who is affected?
================

+---------------------------+-----------------------------------------------+
| Your setup                | What changed for you                          |
+===========================+===============================================+
| Production / live website | **No change.** AI changes still go into a     |
|                           | draft workspace first. Nothing is published   |
|                           | until a human publishes in TYPO3.             |
+---------------------------+-----------------------------------------------+
| DDEV / local development  | **Yes — read on.** AI changes can now update  |
| on your computer          | the **live (published) copy** on that local   |
|                           | site by default.                              |
+---------------------------+-----------------------------------------------+

The production safety model is unchanged. The change only makes local
development feel more like “edit the site you see in the browser” instead of
always creating a hidden draft first.

What are “live” and “draft” in plain language?
==============================================

Think of your TYPO3 site like a magazine:

- **Live content** is what visitors see on the website right now — the
  published version.
- **Draft content** (a *workspace* in TYPO3) is like a separate proof copy.
  Editors can change the proof, review it, and only later decide to publish
  those changes to the live site.

On **production**, the MCP server has always preferred the proof copy. That
protects you from an AI accidentally changing the public website.

On **DDEV / local development**, that extra step often felt annoying: you are
already on a private copy of the site on your machine, and you usually **want**
to see changes immediately.

What changed?
=============

Before this change (local development)
--------------------------------------

Even on DDEV, when an AI tool changed content **without** naming a workspace,
the MCP server usually:

1. Created or picked a **draft workspace** automatically.
2. Saved changes there — **not** on the page you see as “live” on your local
   site.
3. Required you (or the AI) to pass a special ``workspace_id: 0`` parameter
   if you really wanted to edit the published local copy.

That was safe, but confusing on a developer laptop.

After this change (local development)
-------------------------------------

When the server detects a **trusted local environment** (DDEV or TYPO3
*Development* context — see :ref:`how the server decides
<configuration-live-edits-detection>` below):

- If the AI **does not** specify a workspace, MCP now assumes you mean the
  **live copy on your local site**.
- Changes show up the way you expect when you refresh your local DDEV URL.
- You **no longer need** to remember ``workspace_id: 0`` for normal local
  editing.

You can still use a draft workspace locally when you want review-before-publish
behaviour — the AI (or you) just needs to pass an explicit draft
``workspace_id`` (use the ``ListWorkspaces`` tool to find one).

What did **not** change?
========================

- **Production sites** still stage AI edits in a draft workspace by default.
- **Permissions** still apply — the AI can only do what the backend user is
  allowed to do.
- **OAuth / tokens** are unchanged.
- **Files** uploaded through MCP were never workspace-versioned; uploaded
  files still land on disk immediately (see :doc:`../Introduction/Index`).
- You can still **turn local live editing off** entirely or **per user** (see
  below).

.. _configuration-live-edits-detection:

How does the server know it is “local development”?
===================================================

You do not need to configure this for a typical DDEV project. The extension
checks:

- DDEV environment signals (for example ``IS_DDEV_PROJECT``), **or**
- TYPO3 running in a **Development** application context.

The extension setting ``localUnsafeMode`` controls this behaviour:

- **``auto`` (default on DDEV):** local live edits are **on**. DDEV is treated
  as a development site.
- **``on``:** local live edits are **on** even outside DDEV — use only on
  trusted machines.
- **``off``:** local live edits are **off**. Same draft-first behaviour as
  production, even on DDEV.

Integrators can verify the active mode:

.. code-block:: bash

   ddev exec ./vendor/bin/typo3 mcp:get-capabilities --json

Look for ``allows_live_writes: true`` in the output.

How to turn local live editing **off**
======================================

Choose the level that fits your team.

For everyone on this TYPO3 instance
-------------------------------------

In **Admin → Settings → Extension Configuration → MCP Server**, set
**Local development mode** (``localUnsafeMode``) to **Always off**.

Or in ``config/system/settings.php``:

.. code-block:: php

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'off';

Even on DDEV, MCP will go back to draft-first behaviour.

For one user or group (recommended on shared DDEV)
--------------------------------------------------

Use **User TSconfig** so developers get live edits, but editors or test
accounts stay on drafts:

.. code-block:: typoscript

   # Example: only the "integrators" group may edit live on DDEV
   [applicationContext == "Development/DDEV" && backend.user.groupList contains "integrators"]
   options.mcpServer.localUnsafeMode = on
   [else]
   options.mcpServer.localUnsafeMode = off
   [end]

User TSconfig **overrides** the global extension setting for that user.

Ask your integrator to assign the TSconfig to the right backend user or group
in **Backend users → TSconfig** or **Backend user groups → TSconfig**.

Force strict behaviour even on DDEV
-----------------------------------

If you want production-style safety nets on DDEV (draft workspaces **and**
restricted file folders **and** restricted outbound HTTP), set:

.. code-block:: typoscript

   options.mcpServer.strictSandbox = 1

This wins over ``localUnsafeMode`` and DDEV auto-detection.

Everyday scenarios
==================

“I use Cursor / Claude with MCP on my DDEV site”
------------------------------------------------

**Default now:** ask the AI to change content normally. Refresh your local
site — you should see the update on the published local page.

“I want the AI to prepare changes I review before they appear locally”
----------------------------------------------------------------------

Tell the AI to use a **draft workspace**, or pass a ``workspace_id`` for a
named workspace. Use ``ListWorkspaces`` to see available IDs. Then use TYPO3’s
normal workspace publish flow when you are satisfied.

“I connect MCP to our real production server”
---------------------------------------------

Nothing new to do. The AI should continue to work in drafts unless your
integrator has explicitly enabled local mode on production (which they
should not).

“My AI still behaves like before (draft only) on DDEV”
------------------------------------------------------

Check:

1. Is ``localUnsafeMode`` set to ``off`` globally or in your user TSconfig?
2. Is ``strictSandbox`` enabled?
3. Run ``mcp:get-capabilities --json`` — is ``allows_live_writes`` false?

See also :ref:`troubleshooting-live-edits` in :doc:`../Troubleshooting/Index`.

Summary
=======

+----------------------------+-----------------------------------------------+
| Environment                | Default when AI omits ``workspace_id``        |
+============================+===============================================+
| Production                 | Draft workspace (unchanged)                   |
+----------------------------+-----------------------------------------------+
| DDEV / local (default)     | **Live local site** (new)                     |
+----------------------------+-----------------------------------------------+
| DDEV with opt-out TSconfig | Draft workspace                               |
+----------------------------+-----------------------------------------------+

This change makes local AI-assisted editing faster and less confusing. Production
remains protected by the same draft-first rules as before.
