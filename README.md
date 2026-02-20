# Phlo

A programming language and engine built on top of PHP 8+, designed to create compact, clear and performant web apps. Short code, high expressiveness — minimal syntax, no semicolons, compact routes.

## Install

```
composer require phlo/tech
```

Requires PHP 8.1+.

## What is Phlo?

A `.phlo` file compiles to a native PHP class. Properties, methods, views, routes, styles and scripts live together in a single file per component. The transpiler generates PHP classes, JavaScript bundles and CSS stylesheets automatically.

```phlo
prop title = 'Welcome'
prop items => ['Alpha', 'Beta', 'Gamma']

method format($item) => strtoupper($item)

route GET home => view($this)

view:
<h1>$this->title</h1>
<ul>
    <foreach $this->items AS $item>
        <li>{{ $this->format($item) }}</li>
    </foreach>
</ul>

<style>
h1: color: $primary
ul {
    list-style: none
    li: padding: 0.5rem
}
</style>
```

## Guide

Read the full language reference at **[phlo.tech/guide](https://phlo.tech/guide)** — covering syntax, routing, views, CSS, instance management, deployment and more.

## License

Apache-2.0
