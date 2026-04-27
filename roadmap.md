
## Porovnání: Co máme vs. co doporučuje rešerše

### Aktuálně implementováno (9 toolů)

**DIToolkit:**
- `di_get_services` - seznam služeb s filtrováním
- `di_get_service` - detaily jedné služby

**DatabaseToolkit:**
- `db_get_tables` - seznam tabulek
- `db_get_columns` - sloupce tabulky
- `db_get_relationships` - cizí klíče
- `db_suggest_entity` - ⚠️ placeholder (TODO)

TODO: db_suggest_entity() musíme přejmenovat na vhodnější název

**RouterToolkit:**
- `router_match_url` - matchování URL 
- `router_generate_url` - generování URL

### Chybějící funkcionality podle rešerše

| Tool | Popis | Zdroj dat |
|------|-------|-----------|
| `di_get_parameters` | Konfigurační parametry kontejneru | Container |
| `db_explain_query` | EXPLAIN nad SQL dotazem | Database |

| Tool | Popis | Zdroj dat |
|------|-------|-----------|
| `tracy_get_last_exception` | Poslední výjimka 
| `tracy_get_warnings` | Seznam chyb vytvořených přes trigger_error() v posledním requestu

---

## Doporučení z rešerše pro implementaci

### 1. Tracy integrace (nejvyšší přidaná hodnota)

Rešerše zdůrazňuje, že Tracy již sbírá přesně ta data, která AI potřebuje. Místo parsování HTML doporučuje:

```php
// Vlastní Tracy Extension pro JSON export
class McpLogger implements ILogger {
    public function log($value, string $level = self::INFO): ?string {
        // Zapsat do log/mcp_telemetry.jsonl
    }
}
```


Poznámka: cesta k repozitáři Tracy: `/mnt/w/Nette/Tracy`


**Výhody:**
- AI vidí chyby v reálném čase
- Není potřeba parsovat HTML Bluescreen
- Strukturovaná data pro analýzu

### 2. Bezpečnostní doporučení

| Oblast | Doporučení |
|--------|------------|
| **Produkce** | Development-only by default |
| **DB přístup** | Read-only uživatel pro `db_explain_query` |
| **Citlivá data** | Filtrovat hesla, tokeny, API klíče |
| **Environment** | Neexponovat `$_ENV` se secrets |


## Doporučení pro Nette Plugins

### Aktuální stav pluginu

- 10 skills pro `nette` plugin
- 3 skills pro `nette-dev` plugin
- PostToolUse hooks pro Latte/NEON validaci a PHP style fixing
- MCP integrace přes `/install-mcp-inspector` command

### Možná vylepšení

1. **Skill pro MCP nástroje** - vysvětlit AI, jaké MCP tools má k dispozici a kdy je použít
2. **Hook pro Tracy chyby** - notifikovat AI o nových chybách
3. **Rozšířené skills** - dokumentace pro složitější scénáře

---

## Závěr

Projekt MCP-Inspector je architektonicky dobře navržen a dodržuje best practices z rešerše:
- ✅ Správná architektura (Sidecar, Bridge, Lazy loading)
- ✅ Dobrá taxonomie toolů
- ✅ Read-only a idempotent nástroje
- ✅ Extensibilita přes custom toolkity

**Hlavní oblasti pro rozšíření:**
1. Tracy integrace (chyby, dumps, timeline)
2. `di_get_parameters` pro konfiguraci
3. `db_explain_query` pro SQL analýzu
4. Dokončit `db_suggest_entity` (přejmenovat)

# ROADMAPA: MCP-Inspector + Nette Plugins

## Rozšíření core toolkitů (DI + Database)

### 1.1 DIToolkit - nové nástroje

| Tool | Popis | Implementace |
|------|-------|--------------|
| `di_get_parameters` | Konfigurační parametry | `$container->getParameters()` |
| `di_get_extensions` | Seznam extensions | ContainerBuilder reflection |
| `di_find_by_tag` | Služby podle tagu | `$container->findByTag($tag)` |
| `di_find_by_type` | Služby podle typu | `$container->findByType($type)` |

**Soubory:**
- `src/McpInspector/Toolkits/DIToolkit.php`
- `tests/Toolkits/DIToolkit.phpt`

### 1.2 DatabaseToolkit - nové nástroje

| Tool | Popis | Implementace |
|------|-------|--------------|
| `db_explain_query` | EXPLAIN nad SQL | `$connection->query("EXPLAIN $sql")` |
| `db_suggest_entity` | Generovat PHP entity | Dokončit existující placeholder |
| `db_get_indexes` | Indexy tabulky | Structure reflection |

**Bezpečnost pro `db_explain_query`:**
- Whitelist pouze SELECT, SHOW, DESCRIBE, EXPLAIN
- Zakázat INSERT, UPDATE, DELETE, DROP, TRUNCATE
- Parametrizované dotazy

**Soubory:**
- `src/McpInspector/Toolkits/DatabaseToolkit.php`
- `tests/Toolkits/DatabaseToolkit.phpt`

---

## Tracy integrace (nejvyšší přidaná hodnota)

### 2.1 TracyToolkit - nový toolkit

| Tool | Popis | Zdroj dat |
|------|-------|-----------|
| `tracy_get_last_exception` | Poslední výjimka 
| `tracy_get_warnings` | Seznam chyb vytvořených přes trigger_error() v posledním requestu

### 2.2 McpLogger - Tracy rozšíření tracy_get_last_exception

```php
namespace Nette\McpInspector\Tracy;

class McpLogger implements Tracy\ILogger
{
    public function log($value, string $level = self::INFO): ?string
    {
        // Zapsat strukturovaná data do log/mcp_telemetry.jsonl
        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $this->formatValue($value),
            'file' => $value instanceof \Throwable ? $value->getFile() : null,
            'line' => $value instanceof \Throwable ? $value->getLine() : null,
            'trace' => $value instanceof \Throwable ? $this->formatTrace($value) : null,
        ];
        file_put_contents($this->logFile, Json::encode($entry) . "\n", FILE_APPEND);
    }
}
```

### 2.3 tracy_get_warnings() tohle bude chtít úpravu v Tracy. Musíme si implementaci ještě promyslet.



## Nette Plugins rozšíření

repozitář je v `/mnt/w/Nette/x-trifles/Claude-Code`

### 4.1 Nový skill: `mcp-inspector-usage`

Dokumentace pro AI, jak používat MCP nástroje:

```markdown
---
name: mcp-inspector-usage
description: Guide for using MCP Inspector tools effectively
---

# MCP Inspector Tools

## Kdy použít který nástroj

### DI Container
- `di_get_services` - Hledám službu, nevím přesný název
- `di_get_service` - Potřebuji detaily konkrétní služby
- `di_get_parameters` - Zjistit konfigurační hodnoty

### Database
- `db_get_tables` - Přehled struktury databáze
- `db_get_columns` - Detaily tabulky pro psaní SQL
- `db_explain_query` - Optimalizace SQL dotazu

### Router
- `router_match_url` - Který presenter obsluhuje URL?
```

### 4.2 Hook pro Tracy chyby

```bash
#!/bin/bash
# hooks/notify-tracy-errors.sh
# PostToolUse hook - kontrola nových chyb po každé akci

LATEST_ERROR=$(php -r "
    \$log = 'log/mcp_telemetry.jsonl';
    if (file_exists(\$log)) {
        \$lines = file(\$log);
        echo end(\$lines);
    }
")

if [ -n "$LATEST_ERROR" ]; then
    echo "::warning::New error detected in Tracy log"
fi
```

### 4.3 Rozšíření skills

| Skill | Rozšíření |
|-------|-----------|
| `nette-database` | Přidat sekci o MCP nástrojích pro DB |
| `nette-configuration` | Přidat `di_get_parameters` usage |
| `nette-architecture` | Přidat routing introspekci |

**Soubory:**
- `plugins/nette/skills/mcp-inspector-usage/SKILL.md`
- `plugins/nette/hooks/notify-tracy-errors.sh`
- Editace existujících skills

---

## Fáze 5: Dokumentace a best practices

### 5.1 README rozšíření

- Příklady použití každého nástroje
- Bezpečnostní doporučení
- Příklady custom toolkitů

### 5.2 CLAUDE.md aktualizace

- Dokumentace nových toolkitů
- Příklady atributů a anotací


