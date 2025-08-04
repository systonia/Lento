---
namespace: {{namespace}}
class: {{class}}
---

# {{namespace}}\{{class}}

{{docblock}}

## Attributes
{{#each attributes}}
- **{{name}}**{{#if args}}({{args}}){{/if}}
{{/each}}

## Properties
| Name | Type | Attributes | Docblock |
|------|------|------------|----------|
{{#each properties}}
| `{{name}}` | {{type}} | {{attributesString}} | {{docblock}} |
{{/each}}


