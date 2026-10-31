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

> ⚠️ **This plugin is a pre-release.** It is not yet published on the Moodle Plugin Directory.

[English](#english) | [Português](#português)

---

## English

**PlayerPuzzle** is a turn-based Match-3 RPG gamified activity for Moodle: the student combines
pieces on an 8×8 board to attack an opponent controlled by a simple AI, answering questions to land
critical hits. The questions come from the activity's own bank — imported from the course's Moodle
Question Bank, written by hand, or generated with AI and approved by the teacher. The teacher
chooses between a self-contained **Single Match** or a **Campaign** of up to 10 levels (10 phases
each). Winning earns **PuzzleCoin**, spent in a loadout shop before the next match; the optional
companion `block_playerhud` can feed coins in, charge for a retry or award an item.

📚 **[Full documentation](https://jeanlucio.github.io/moodle-mod_playerpuzzle/)** — features
how combat and the question engine work, game modes and grading, the loadout shop and the
PlayerHUD integration, accessibility, the anti-cheat design, the automated test suite with
coverage, and third-party service disclosure.

### 🔎 Third-party Service Disclosure

PlayerPuzzle works without any external service. The only feature that reaches one is the
**optional** AI question generator on the *Manage questions* page, available to teachers who hold
the `mod/playerpuzzle:managequestions` capability. When a teacher uses it, the plugin sends a
prompt made of the topic they typed, the number of questions requested, the interface language and
fixed formatting instructions. No student data and no course content is included. The request goes
through the companion [local_aihub](https://github.com/jeanlucio/moodle-local_aihub) plugin when it
is installed and has a key configured, otherwise through Moodle's own `core_ai` subsystem, so the
provider is whichever one the administrator configured there. If neither is available the
generator is simply not offered. Generated questions are saved as pending and never reach a
student before the teacher approves them.

Full disclosure:
[Third-party Service Disclosure](https://jeanlucio.github.io/moodle-mod_playerpuzzle/#third-party).

### 📦 Requirements

| Component | Version |
|-----------|---------|
| Moodle    | 4.5 – 5.3 |
| PHP       | 8.1+    |
| PlayerHUD *(optional)* | v1.7.1+ |

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
6. Add a PlayerPuzzle activity to any course, pick a **Game Mode** and (optionally) which
   PlayerHUD items to use. Then open **Manage questions** to import a Question Bank category, add
   questions by hand or generate them with AI.

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

> ⚠️ **Este plugin é uma pré-versão.** Ainda não foi publicado no Diretório de Plugins do Moodle.

O **PlayerPuzzle** é uma atividade gamificada Match-3 RPG por turnos para o Moodle: o estudante
combina peças num tabuleiro 8×8 para atacar um adversário controlado por uma IA simples, respondendo
questões para desferir ataques críticos. As questões vêm do banco da própria atividade —
importadas do Banco de Questões do Moodle do curso, escritas à mão, ou geradas por IA e aprovadas
pelo professor. O professor escolhe entre uma **Partida Única** autocontida ou uma **Campanha** de
até 10 níveis (10 fases cada). Vencer rende **PuzzleCoin**, gasto numa loja de loadout antes da
próxima partida; o `block_playerhud` companheiro, opcional, pode alimentar moedas, cobrar por uma
nova tentativa ou conceder um item.

📚 **[Documentação completa](https://jeanlucio.github.io/moodle-mod_playerpuzzle/pt.html)** —
como o combate e o motor de questões funcionam, modos de jogo e nota, a loja de loadout e a
integração com o PlayerHUD, acessibilidade, o desenho anti-trapaça, a suíte de testes automatizados
com cobertura, e a divulgação de serviço de terceiros.

### 🔎 Divulgação de Serviço de Terceiros

O PlayerPuzzle funciona sem nenhum serviço externo. O único recurso que chega a um é o gerador
de questões por IA, **opcional**, na página *Gerenciar questões*, disponível a quem tem a
capability `mod/playerpuzzle:managequestions`. Quando o professor o usa, o plugin envia um prompt
composto pelo tema digitado, pela quantidade de questões pedida, pelo idioma da interface e por
instruções fixas de formato. Nenhum dado de estudante nem conteúdo do curso é incluído. O pedido
passa pelo plugin companheiro [local_aihub](https://github.com/jeanlucio/moodle-local_aihub)
quando ele está instalado e tem uma chave configurada, e senão pelo subsistema `core_ai` do próprio
Moodle, de modo que o provedor é o que o administrador configurou ali. Se nenhum dos dois estiver
disponível, o gerador simplesmente não é oferecido. As questões geradas ficam pendentes e nunca
chegam a um estudante antes de o professor aprová-las.

Divulgação completa:
[Divulgação de Serviço de Terceiros](https://jeanlucio.github.io/moodle-mod_playerpuzzle/pt.html#third-party).

### 📦 Requisitos

| Componente | Versão |
|------------|--------|
| Moodle     | 4.5 – 5.3 |
| PHP        | 8.1+   |
| PlayerHUD *(opcional)* | v1.7.1+ |

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
6. Adicione uma atividade PlayerPuzzle a qualquer curso, escolha um **Modo de Jogo** e
   (opcionalmente) quais itens do PlayerHUD usar. Depois abra **Gerenciar questões** para importar
   uma categoria do Banco de Questões, cadastrar questões à mão ou gerá-las com IA.

### 🆘 Suporte

Encontrou um bug ou tem alguma dúvida? Abra uma issue no
[rastreador de issues](https://github.com/jeanlucio/moodle-mod_playerpuzzle/issues).

### 📄 Licença

Este projeto é licenciado sob a **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Mantenedor

Mantido por [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Voltar ao topo](#português)
