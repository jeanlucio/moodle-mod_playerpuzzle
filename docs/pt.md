---
layout: default
title: Documentação do PlayerPuzzle
lang: pt
---

![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat&logo=moodle&logoColor=white)
![Licença](https://img.shields.io/badge/Licen%C3%A7a-GPLv3-blue?style=flat)
![Status](https://img.shields.io/badge/Status-Alpha-red?style=flat)
[![Última Versão](https://img.shields.io/github/v/release/jeanlucio/moodle-mod_playerpuzzle?style=flat)](https://github.com/jeanlucio/moodle-mod_playerpuzzle/releases)
[![Ecossistema Player](https://img.shields.io/badge/Player-Ecossistema-6f42c1?style=flat&logo=gamepad&logoColor=white)](#ecosystem)
[![Autor](https://img.shields.io/badge/por-Jean_Lucio-6f42c1?style=flat)](https://github.com/jeanlucio/)

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-mod_playerpuzzle/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-mod_playerpuzzle/actions/workflows/ci.yml)
[![Último Commit](https://img.shields.io/github/last-commit/jeanlucio/moodle-mod_playerpuzzle?style=flat)](https://github.com/jeanlucio/moodle-mod_playerpuzzle/commits)
[![Issues Abertas](https://img.shields.io/github/issues/jeanlucio/moodle-mod_playerpuzzle?style=flat)](https://github.com/jeanlucio/moodle-mod_playerpuzzle/issues)

> ⚠️ **Este plugin está em desenvolvimento ativo.** Ainda não foi publicado no Diretório de
> Plugins do Moodle. Algumas funcionalidades descritas abaixo são planejadas e ainda não estão
> implementadas — veja [Funcionalidades](#features) pra saber exatamente quais são quais.

O **PlayerPuzzle** (`mod_playerpuzzle`) é uma atividade gamificada Match-3 RPG por turnos para o
Moodle: o estudante combina peças num tabuleiro 8×8 para atacar um chefe controlado por uma IA
simples, respondendo questões puxadas do próprio Banco de Questões do Moodle no curso pra
desferir ataques críticos. O professor escolhe entre uma **Partida Única** autocontida ou uma
**Campanha** de até 10 níveis (10 fases cada); a progressão permanente opcional (moedas, nível
de espada/escudo) é resolvida inteiramente pelo [block_playerhud](#playerhud) companheiro,
quando presente no curso — o PlayerPuzzle não mantém economia própria.

<p class="page-hint">👈 Use a barra lateral para ir direto a qualquer seção desta página.</p>

---

<span id="screenshots"></span>
{% include_relative pt/screenshots.md %}

<span id="features"></span>
{% include_relative pt/features.md %}

<span id="combat"></span>
{% include_relative pt/combat.md %}

<span id="questions"></span>
{% include_relative pt/questions.md %}

<span id="game-modes"></span>
{% include_relative pt/game-modes.md %}

<span id="playerhud"></span>
{% include_relative pt/playerhud.md %}

<span id="accessibility"></span>
{% include_relative pt/accessibility.md %}

<span id="security"></span>
{% include_relative pt/security.md %}

<span id="educational-purpose"></span>
{% include_relative pt/educational-purpose.md %}

<span id="ecosystem"></span>
{% include_relative pt/ecosystem.md %}

<span id="requirements"></span>
{% include_relative pt/requirements.md %}

<span id="installation"></span>
{% include_relative pt/installation.md %}

<span id="usage"></span>
{% include_relative pt/usage.md %}

<span id="testing"></span>
{% include_relative pt/testing.md %}

<span id="third-party"></span>
{% include_relative pt/third-party.md %}

<span id="license"></span>
{% include_relative pt/license.md %}
