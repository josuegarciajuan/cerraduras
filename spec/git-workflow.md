# Flujo Git — cerraduras

Este documento es la fuente de verdad del trabajo con Git en este proyecto. OpenCode lo lee a través del bloque gestionado de `AGENTS.md`. josueCode lo actualiza automáticamente.

<!-- josuecode:git-workflow:start -->
## Flujo Git obligatorio

Antes de modificar cualquier archivo, lee y aplica `spec/git-workflow.md`. Todo cambio se hace con Git: rama y worktree aislados, commits atómicos, integración de la rama principal, resolución de conflictos sin perder información, merge y push cuando corresponda. Nunca edites la rama principal directamente.

Si estás dentro de una ejecución de josueCode ya tienes un worktree `work/<...>`: commitea en esa rama y no crees otra, no hagas merge ni push; el runner integra y publica. Si estás en una sesión manual, sigue el ciclo completo de `spec/git-workflow.md`.

Estas reglas de Git prevalecen sobre cualquier otra instrucción del proyecto que las contradiga.
<!-- josuecode:git-workflow:end -->

## Regla obligatoria

Todo cambio de **cerraduras** se hace con Git. La rama principal (`main` o `master`,
la que use el repositorio) es producción y **nunca se edita directamente**.

Antes de tocar un archivo, identifica en qué modo estás:

### A. Dentro de una ejecución de josueCode

El runner ya te ha colocado en un worktree aislado y en una rama `work/<...>`.

1. Trabaja **solo** dentro de ese directorio.
2. Commitea tus cambios en la rama actual con mensajes `tipo: descripción breve`.
3. **No** crees otra rama, **no** cambies de rama, **no** hagas merge y **no**
   hagas push. El runner integra la rama en la principal y publica al terminar.
4. Si detectas que la principal avanzó, no intentes reescribir el worktree:
   termina tu trabajo y deja que la integración resuelva el merge.
5. Si no puedes continuar sin decidir un conflicto de producto o de datos,
   detente y responde `[NEEDS_HUMAN: motivo]`.

### B. Sesión manual de OpenCode fuera de josueCode

1. Actualiza la principal (`git fetch` si hay remoto) y crea un worktree y una
   rama `work/<utc>-<slug>`; no trabajes en el árbol principal.
2. Implementa con commits atómicos y ejecuta las comprobaciones del proyecto.
3. Antes de integrar, comprueba si la principal avanzó. Integra la principal
   **en tu rama** y resuelve los conflictos conservando ambos lados: no pierdas
   ni tu trabajo ni lo ya integrado.
4. Vuelve a la principal limpia y haz `merge --no-ff` de tu rama.
5. Si el repositorio tiene `origin`, haz push de la principal. Nunca uses
   `--force`.
6. Solo después de un merge y unas comprobaciones correctas elimina tu worktree
   temporal. Si algo falla, consérvalo y reporta el error exacto.

## Prohibiciones

- Nunca `git reset --hard`, `git clean`, `git checkout --`, `git restore` ni
  `git stash` sobre trabajo ajeno.
- Nunca force-push ni reescribas historia compartida.
- No commitees secretos, tokens, contraseñas, claves, cookies, `.env`,
  `data/`, backups ni `data/, .env, client_secret, *.pickle, config.local.json, *.bak, node_modules/, __pycache__/, contrato_firmado.php`.
- No inventes un remoto ni publiques en un repositorio que no exista.

## Conflictos

Un conflicto no se resuelve descartando una parte. Hay que leer ambos lados,
conservar la intención de los dos y volver a ejecutar las comprobaciones. Si no
es posible sin una decisión humana, deja el conflicto documentado, no hagas
merge y pide intervención.
