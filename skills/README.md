### Skills for spiral/idempotency users

AI skills for projects that use **spiral/idempotency**. Each subdirectory contains a `SKILL.md`
(Anthropic Skill format: frontmatter + Markdown body) that an AI coding agent can load on demand.

| Skill | Use when… |
|---|---|
| [`spiral-idempotency`](spiral-idempotency/SKILL.md) | Setting up the package (bootloaders, `app/config/idempotency.php`, interceptor wiring, tables) or making a handler idempotent — `#[Idempotent]` over HTTP / queue / gRPC, choosing a guarantee, ExactlyOnce transactional writes, failure replay, GC. |

### Authoritative source of truth

The skill encodes *when* to act and *what shape* the answer should take; the detailed reference is
the package README (`vendor/spiral/idempotency/README.md` in a consumer project), which the skill
points at for anything it does not cover.

### Installing into a project

Projects using the [`llm/skills`](https://github.com/roxblnfk/skills) Composer plugin with
`discovery` enabled pick this directory up automatically. Otherwise, copy the skill directory into
the project's `.claude/skills/` (or any skills root the agent is configured to read).
