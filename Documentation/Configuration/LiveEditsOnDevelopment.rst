.. include:: /Includes.rst.txt

.. _configuration-live-edits-development:

=====================================================
Live edits and MCP chatbots (DDEV, production, overrides)
=====================================================

This page explains how the MCP server decides whether a **chatbot** (Cursor,
Claude, n8n, or any MCP client) changes **live (published) content** or
**draft (workspace) content**. It is written for editors, project owners, and
integrators who are **not** deep into TYPO3 internals.

.. contents::
   :local:
   :depth: 2

Quick comparison: DDEV vs not DDEV
==================================

Use this when you connect a chatbot **over MCP** and ask it to change pages,
text, or records.

+----------------------------+---------------------------+---------------------------+
|                            | **On DDEV (default)**     | **Not DDEV / production** |
+============================+===========================+===========================+
| Local mode                 | On automatically          | Off (``auto`` default)    |
+----------------------------+---------------------------+---------------------------+
| ``allows_live_writes``     | Usually ``true``          | ``false``                 |
+----------------------------+---------------------------+---------------------------+
| Chatbot omits workspace    | Edits **live local site** | Edits **draft workspace** |
+----------------------------+---------------------------+---------------------------+
| Visitors see changes       | Yes (your DDEV URL)       | **No** until publish      |
+----------------------------+---------------------------+---------------------------+
| Publish step               | Usually not needed        | Required                  |
+----------------------------+---------------------------+---------------------------+
| Can override to live?      | Yes (turn **off** via     | Yes (turn **on** via      |
|                            | TSconfig — see below)     | TSconfig — see below)     |
+----------------------------+---------------------------+---------------------------+

Verify at any time (ask the chatbot or run CLI):

.. code-block:: bash

   ddev exec ./vendor/bin/typo3 mcp:get-capabilities --json

Look for ``allows_live_writes`` and ``localMode.enabled``.

Decision tree (diagram)
=======================

The flow below shows **how MCP requests enter TYPO3**, which **environment and
settings** decide ``local mode``, and where the chatbot's changes land (**live**
vs **draft**) when no ``workspace_id`` is passed.

Paste the diagram into `Mermaid Live <https://mermaid.live/>`__ to explore it
interactively, or view it rendered in the repository ``README.md`` (same
diagram).

.. code-block:: text
   :caption: MCP entry points and live-vs-draft decision tree (Mermaid source)

   flowchart TB
       subgraph EP["① MCP entry points"]
           HTTP["Remote HTTP /mcp<br/>OAuth token → real backend user"]
           STDIO["Local stdio<br/>Cursor Install → ddev exec mcp:server"]
           CLI["TYPO3 CLI<br/>vendor/bin/typo3 mcp:…"]
       end

       subgraph ENV["② Hosting context examples"]
           DDEV["DDEV<br/>IS_DDEV_PROJECT, …"]
           DEVCTX["Development context<br/>TYPO3_CONTEXT=Development/…"]
           PROD["Production<br/>Production context, no DDEV vars"]
           STAGE["Staging / demo<br/>same rules as PROD unless overridden"]
       end

       EP --> CTX["Backend user + workspace context"]
       HTTP --> REAL["Token owner: permissions + User TSconfig apply"]
       STDIO --> SYNTH["Synthetic admin uid 1<br/>extension / auto policy mainly"]

       CTX --> POLICY

       subgraph POLICY["③ LocalModeService — local mode on?"]
           SB{"strictSandbox = 1?<br/>feature flag or User TSconfig"}
           SB -- strict --> OFF["local mode OFF<br/>allows_live_writes: false"]
           SB -- not strict --> CFG{"localUnsafeMode<br/>User TSconfig overrides extension"}
           CFG -- on --> ON["local mode ON<br/>allows_live_writes: true"]
           CFG -- off --> OFF
           CFG -- auto --> AUTO{"DDEV env vars<br/>OR Development context?"}
           AUTO -- detected --> ON
           AUTO -- not detected --> OFF
       end

       POLICY --> WS

       subgraph WS["④ Default workspace when chatbot omits workspace_id"]
           CUR{"User already in<br/>draft workspace?"}
           CUR -- yes --> DRAFT["Draft workspace<br/>staged changes"]
           CUR -- no --> ALLOW{"allows_live_writes?"}
           ALLOW -- yes --> LIVE["Live workspace 0<br/>published content"]
           ALLOW -- no --> PICK["Pick or create<br/>MCP draft workspace"]
       end

       EX["Explicit workspace_id in tool call"] --> EX0{"workspace_id = 0?"}
       EX0 -- "0 + allowed" --> LIVE
       EX0 -- "0 + denied" --> DENY["AccessDenied:<br/>live workspace"]
       EX0 -- "draft id > 0" --> DRAFT

       DDEV -.-> AUTO
       DEVCTX -.-> AUTO
       PROD -.-> AUTO
       STAGE -.-> CFG

Reading the diagram
-------------------

**Entry points (①)**

- **Remote HTTP** — Claude Desktop, n8n, Manus, etc. using the MCP URL from
  the backend module. Uses the **real backend user** who created the OAuth token.
- **Local stdio** — Cursor “Install in Cursor” / ``mcp:server``. Runs inside
  DDEV on your laptop; uses a **built-in admin user**, not your personal
  TSconfig (unless you change that setup).
- **TYPO3 CLI** — ``vendor/bin/typo3 mcp:write-table`` and similar; same
  workspace rules as MCP tools.

**Hosting context (②)** — not a separate code path; it feeds the ``auto``
branch. DDEV and Development context typically enable local mode; production
typically does not.

**Policy (③)** — ``strictSandbox`` always wins. Otherwise ``localUnsafeMode``
(from User TSconfig or extension) decides. See
:ref:`configuration-live-edits-production-override` for production opt-in.

**Workspace outcome (④)** — after policy is resolved, every record tool call
picks live or draft. Passing ``workspace_id`` explicitly bypasses the default
branch (bottom of diagram).

Who is affected?
================

+---------------------------+-----------------------------------------------+
| Your setup                | What this means for your chatbot              |
+===========================+===============================================+
| Production / live website | **Default:** draft-first (safe). Live edits   |
|                           | on production are possible but must be        |
|                           | enabled deliberately — see production         |
|                           | override section below.                       |
+---------------------------+-----------------------------------------------+
| DDEV / local development  | **Default:** live local edits when the        |
| on your computer          | chatbot omits a workspace.                    |
+---------------------------+-----------------------------------------------+

The **default** production safety model is unchanged (draft-first). The change
makes local development feel like “edit the site you see in the browser.”
Production live edits are **opt-in only** — see
:ref:`configuration-live-edits-production-override`.

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

**Default:** the chatbot works in **drafts**. That is the safe setting for a
public website.

If you **want DDEV-like live editing on production** (chatbot changes the
real site immediately), that is possible but **dangerous** — read
:ref:`configuration-live-edits-production-override` before enabling it.

.. _configuration-live-edits-production-override:

Forcing DDEV-like behaviour on production (override)
====================================================

.. danger::

   **Do not enable this on a public production website unless you fully accept
   the risk.** When live writes are on, a chatbot connected over MCP can change
   **what visitors see immediately** — there is no automatic draft step. A
   mistaken AI instruction can publish bad content straight to the live site.

   Prefer keeping production on **draft-first** and using DDEV (or a staging
   clone) for “edit what I see in the browser” workflows.

What “DDEV-like on production” means
------------------------------------

If you override local mode on a **non-DDEV** server, the chatbot gets the
**same MCP behaviour as on DDEV**, including:

- **Record tools:** omitting ``workspace_id`` edits the **live (published)**
  database rows — not a hidden workspace draft.
- **File tools:** can reach paths outside the default ``fileadmin/mcp/``
  sandbox (when local mode relaxes file access).
- **Outbound HTTP:** ``UploadFileFromUrl`` and ``RenderRecord`` are less
  restricted (manifest outbound allowlist and some SSRF checks are bypassed).
- **Dev-site tools:** may become visible (same ``localMode`` gate).

Authentication, backend-user permissions, and the capability manifest still
apply — but **workspace staging no longer protects you from instant live
changes**.

When might an override be acceptable?
-------------------------------------

Examples integrators sometimes consider (still use with care):

- A **private staging server** that mirrors production but is not public
- A **demo instance** with no real visitors
- A **single trusted admin** testing MCP on a clone, with backups

It is **not** appropriate for a typical customer-facing production TYPO3 site
with editor chatbots.

How to enable live editing on production
----------------------------------------

There are three levels. **Per-user User TSconfig is strongly preferred** over
enabling the whole instance globally.

Option 1 — One backend user or group only (recommended IF you must)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Assign **User TSconfig** to the backend user (or group) whose **OAuth MCP
token** the chatbot uses:

.. code-block:: typoscript

   # Only this user/group gets live MCP writes on production
   options.mcpServer.localUnsafeMode = on

Use TYPO3 conditions to narrow further, for example a dedicated ``mcp-live``
group:

.. code-block:: typoscript

   [backend.user.groupList contains "mcp-live"]
   options.mcpServer.localUnsafeMode = on
   [else]
   options.mcpServer.localUnsafeMode = off
   [end]

Where to paste this:

- **Backend → Backend users → [user] → TSconfig**, or
- **Backend → Backend user groups → [group] → TSconfig**

**Important for chatbots:** Remote MCP (Claude Desktop, n8n, OAuth URL) uses
the **token owner's** TSconfig. The Cursor **“Install in Cursor”** stdio setup
runs as a synthetic admin inside DDEV and does **not** use your personal
TSconfig — that path is for local dev only.

Option 2 — Whole TYPO3 instance (avoid on real production)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

In **Admin → Settings → Extension Configuration → MCP Server**, set
**Local development mode** (``localUnsafeMode``) to **Always on**.

Or in ``config/system/settings.php``:

.. code-block:: php

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';

This affects **every** MCP user on that installation. Only use on non-public
staging or demo systems.

Option 3 — Misconfiguration to avoid
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Do **not** rely on accidentally running production with
``TYPO3_CONTEXT=Development/...`` or DDEV environment variables on a public
server — ``localUnsafeMode = auto`` would then enable live writes without you
meaning to. Production should use a **Production** application context and no
DDEV env vars.

What does **not** enable live writes on production
----------------------------------------------------

These alone are **not** enough:

- Passing ``workspace_id: 0`` once — blocked when ``allows_live_writes`` is
  false (you get ``AccessDenied: live workspace``).
- Expecting ``auto`` on production — without DDEV or Development context,
  ``auto`` stays **off**.

You must set ``localUnsafeMode`` to resolve to **``on``** (extension or User
TSconfig), and ``strictSandbox`` must **not** be enabled.

Verify after enabling
---------------------

1. Ask the chatbot: *“Call GetCapabilities and report allows_live_writes.”*
2. Or run:

   .. code-block:: bash

      vendor/bin/typo3 mcp:get-capabilities --json

3. Expect ``allows_live_writes: true`` and ``localMode.enabled: true`` for
   that backend user.
4. Make a harmless test edit via the chatbot and confirm it appears on the
   **live frontend** without a workspace publish step.

How to revert (back to safe production behaviour)
-------------------------------------------------

Pick one:

**Per user/group** — remove or set:

.. code-block:: typoscript

   options.mcpServer.localUnsafeMode = off

**Globally** — extension setting ``localUnsafeMode = off`` or ``auto`` (with
Production context and no DDEV vars).

**Force strict everywhere** (overrides ``on``):

.. code-block:: typoscript

   options.mcpServer.strictSandbox = 1

Or the feature flag:

.. code-block:: php

   $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox'] = true;

Re-check ``GetCapabilities`` — ``allows_live_writes`` should be ``false`` and
the chatbot should return to **draft-first** behaviour.

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
| Environment                | Default when chatbot omits ``workspace_id``   |
+============================+===============================================+
| Production (default)       | Draft workspace                               |
+----------------------------+-----------------------------------------------+
| Production + override      | **Live site** (dangerous — opt-in only)       |
+----------------------------+-----------------------------------------------+
| DDEV / local (default)     | **Live local site**                           |
+----------------------------+-----------------------------------------------+
| DDEV with opt-out TSconfig | Draft workspace                               |
+----------------------------+-----------------------------------------------+

On DDEV, local AI-assisted editing is faster and matches what you see in the
browser. On production, **draft-first remains the default**; enable live MCP
writes only deliberately via :ref:`configuration-live-edits-production-override`.
