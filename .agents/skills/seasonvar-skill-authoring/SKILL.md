---
name: seasonvar-skill-authoring
description: "Use when creating or updating Seasonvar project skills, `.agents/skills/*/SKILL.md`, skill metadata, `boost.json` skill registration, MCP documentation, `.codex` examples, or safe connector setup plans for this repository."
---

# Seasonvar Skill Authoring

## Overview

Create small project skills that capture Seasonvar-specific workflows without embedding secrets, OAuth credentials, broad connector assumptions, or generic Laravel boilerplate.

## Required Workflow

- Read `/root/.codex/skills/.system/skill-creator/SKILL.md` before creating or updating a skill.
- Prefer `.agents/skills` for repository-specific Seasonvar skills and update `boost.json` so Laravel Boost can see them.
- Use the skill-creator initializer when creating a new skill:

```bash
python3 /root/.codex/skills/.system/skill-creator/scripts/init_skill.py <skill-name> --path .agents/skills
```

- Replace template placeholder text with concise instructions and keep `SKILL.md` under 500 lines.
- Validate every edited skill:

```bash
python3 /root/.codex/skills/.system/skill-creator/scripts/quick_validate.py .agents/skills/<skill-name>
```

## Skill Content Rules

- Put trigger conditions in frontmatter `description`; do not rely on a body "when to use" section for discovery.
- Keep skills procedural and project-specific: files to inspect, services to respect, commands to run, and failure modes to watch.
- Create `references/`, `scripts/`, or `assets/` only when they directly reduce repeated work.
- Do not add README, changelog, installation guide, or broad documentation inside a skill folder.
- Do not duplicate AGENTS.md; reference project docs instead.

## MCP Rules

- Keep project `.codex/config.toml` limited to safe project-local MCP servers that require no personal credentials.
- Put OAuth, tokens, remote connector login, and personal MCP servers in user/global config only.
- Use `tool_search`, `codex mcp list`, and `php artisan integrations:doctor --json` before claiming a connector is active.
- Document missing connector authorization instead of inventing a configured MCP.

## Documentation

- Update `docs/mcp.md` and `docs/integrations/mcp-catalog.md` when adding project skills or changing MCP policy.
- Keep `.codex/mcp.example.toml` as examples only; never paste real secrets.
- Run focused syntax/config checks for changed docs/config, and avoid cache-clearing commands unless explicitly requested.
