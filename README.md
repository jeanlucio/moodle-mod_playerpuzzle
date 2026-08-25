# Moodle Mod PlayerPuzzle

![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat)
![Status](https://img.shields.io/badge/Status-Alpha-red?style=flat)
[![Latest Release](https://img.shields.io/github/v/release/jeanlucio/moodle-mod_playerpuzzle?style=flat)](https://github.com/jeanlucio/moodle-mod_playerpuzzle/releases)
[![PlayerGames Ecosystem](https://img.shields.io/badge/PlayerGames-Ecosystem-6f42c1?style=flat&logo=gamepad&logoColor=white)](https://jeanlucio.github.io/playergames/)
[![Author](https://img.shields.io/badge/by-Jean_Lucio-6f42c1?style=flat)](https://github.com/jeanlucio/)

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-mod_playerpuzzle/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-mod_playerpuzzle/actions/workflows/ci.yml)
[![Last Commit](https://img.shields.io/github/last-commit/jeanlucio/moodle-mod_playerpuzzle?style=flat)](https://github.com/jeanlucio/moodle-mod_playerpuzzle/commits)
[![Open Issues](https://img.shields.io/github/issues/jeanlucio/moodle-mod_playerpuzzle?style=flat)](https://github.com/jeanlucio/moodle-mod_playerpuzzle/issues)

> ⚠️ **This plugin is under active development.** It is not yet published on the Moodle Plugin Directory. Some features described in the full documentation are planned and not yet implemented.

[English](#english) | [Português](#português)

---

## English

**PlayerPuzzle** is a turn-based Match-3 RPG gamified activity for Moodle: the student combines
pieces on an 8×8 board to attack a boss controlled by a simple AI, answering questions pulled
from the course's own Moodle Question Bank to land critical hits. The teacher chooses between a
self-contained **Single Match** or a **Campaign** of up to 10 levels (10 phases each); optional
permanent progression (coins, sword/shield level) is handled entirely by the companion
`block_playerhud` when present in the course — PlayerPuzzle keeps no economy of its own.

📚 **[Full documentation](https://jeanlucio.github.io/moodle-mod_playerpuzzle/)** — features
(implemented vs. planned), how combat and the question engine work, game modes, the PlayerHUD
integration, accessibility, the three-pillar anti-cheat design, the 104-case test suite with
coverage, and third-party service disclosure.

### 🔎 Third-party Service Disclosure

Not applicable today. PlayerPuzzle does not call any external service — every question comes
from Moodle's own Question Bank. An optional, AI-assisted second question source is planned for
a future release, routed through the companion
[local_aihub](https://github.com/jeanlucio/moodle-local_aihub) plugin with a `core_ai` fallback,
never including student data in a prompt.

Full disclosure:
[Third-party Service Disclosure](https://jeanlucio.github.io/moodle-mod_playerpuzzle/#third-party).

### 📦 Requirements

| Component | Version |
|-----------|---------|
| Moodle    | 4.5 – 5.2 |
| PHP       | 8.2+    |

### 🛠️ Installation & Configuration

> ⚠️ This plugin is not yet published on the Moodle Plugin Directory. Install manually from this repository.

1. Download the `.zip` file or clone this repository.
2. Extract the folder into your Moodle `mod/` directory.
3. Rename the folder to `playerpuzzle` (if necessary).
   Final path:
   `your-moodle/mod/playerpuzzle/`
4. Visit **Site administration > Notifications** to complete installation.
5. Optional: install **[block_playerhud](https://github.com/jeanlucio/moodle-block_playerhud)**
   for the optional coin/upgrade economy.
6. Add a PlayerPuzzle activity to any course, pick a **Game Mode**, a Question Bank category, and
   (optionally) which PlayerHUD items to use.

### 🆘 Support

Found a bug or have a question? Open an issue on the
[issue tracker](https://github.com/jeanlucio/moodle-mod_playerpuzzle/issues).

### 📄 License

This project is licensed under the **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Maintainer

Maintained by [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Back to top](#english)

---

## Português

> ⚠️ **Este plugin está em desenvolvimento ativo.** Ainda não foi publicado no Diretório de Plugins do Moodle. Algumas funcionalidades descritas na documentação completa são planejadas e ainda não estão implementadas.

O **PlayerPuzzle** é uma atividade gamificada Match-3 RPG por turnos para o Moodle: o estudante
combina peças num tabuleiro 8×8 para atacar um chefe controlado por uma IA simples, respondendo
questões puxadas do próprio Banco de Questões do Moodle no curso pra desferir ataques críticos.
O professor escolhe entre uma **Partida Única** autocontida ou uma **Campanha** de até 10 níveis
(10 fases cada); a progressão permanente opcional (moedas, nível de espada/escudo) é resolvida
inteiramente pelo `block_playerhud` companheiro, quando presente no curso — o PlayerPuzzle não
mantém economia própria.

📚 **[Documentação completa](https://jeanlucio.github.io/moodle-mod_playerpuzzle/pt.html)** —
funcionalidades (implementadas vs. planejadas), como o combate e o motor de questões funcionam,
modos de jogo, a integração com o PlayerHUD, acessibilidade, o desenho anti-trapaça em três
pilares, a suíte de 104 testes com cobertura, e a divulgação de serviço de terceiros.

### 🔎 Divulgação de Serviço de Terceiros

Não aplicável hoje. O PlayerPuzzle não chama nenhum serviço externo — toda questão vem do
próprio Banco de Questões do Moodle. Uma segunda fonte de questões, opcional e assistida por IA,
está planejada para uma versão futura, roteada pelo plugin companheiro
[local_aihub](https://github.com/jeanlucio/moodle-local_aihub) com fallback pro `core_ai`, nunca
incluindo dados de estudante num prompt.

Divulgação completa:
[Divulgação de Serviço de Terceiros](https://jeanlucio.github.io/moodle-mod_playerpuzzle/pt.html#third-party).

### 📦 Requisitos

| Componente | Versão |
|------------|--------|
| Moodle     | 4.5 – 5.2 |
| PHP        | 8.2+   |

### 🛠️ Instalação e Configuração

> ⚠️ Este plugin ainda não está publicado no Diretório de Plugins do Moodle. Instale manualmente a partir deste repositório.

1. Baixe o arquivo `.zip` ou clone este repositório.
2. Extraia na pasta `mod/` do seu Moodle.
3. Renomeie para `playerpuzzle` (se necessário).
   Caminho final:
   `seu-moodle/mod/playerpuzzle/`
4. Acesse **Administração do site > Notificações** para concluir a instalação.
5. Opcional: instale o **[block_playerhud](https://github.com/jeanlucio/moodle-block_playerhud)**
   pra economia opcional de moedas/upgrades.
6. Adicione uma atividade PlayerPuzzle a qualquer curso, escolha um **Modo de Jogo**, uma
   categoria do Banco de Questões, e (opcionalmente) quais itens do PlayerHUD usar.

### 🆘 Suporte

Encontrou um bug ou tem alguma dúvida? Abra uma issue no
[rastreador de issues](https://github.com/jeanlucio/moodle-mod_playerpuzzle/issues).

### 📄 Licença

Este projeto é licenciado sob a **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Mantenedor

Mantido por [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Voltar ao topo](#português)
