# 💬 Zágora — assistente financeiro no Telegram

**Bot de Telegram que organiza dinheiro, agenda e dia a dia por conversa.** O utilizador escreve em linguagem natural ("gastei 20 no mercado", "quanto tenho?", "lembra-me de pagar a internet sexta") e o Zágora regista, consulta e organiza gastos, receitas, contas, lembretes e metas, sem formulários.

🔗 **Site:** [zagora.space](https://zagora.space) · **Bot:** [@ozagora_bot](https://t.me/ozagora_bot)

---

## ✨ Funcionalidades

- **Gastos e receitas por conversa**, com categoria aprendida por estabelecimento ("pingo doce" → mercado) e várias moedas (EUR, BRL, USD), que nunca se somam entre si.
- **Contas e saldos**, com transferências entre contas e deteção de nomes com erros de escrita (Levenshtein) para não criar contas duplicadas.
- **Lembretes e contas a pagar**, únicos ou recorrentes (diário, semanal, mensal, a cada X horas), com botões "Paguei", "Amanhã" e "Daqui a 1h".
- **Orçamentos por categoria** com alertas aos 80% e 100%, e **metas de poupança** com marcos de progresso.
- **Resumos semanais e mensais** automáticos, com comparação com o período anterior.
- **Listas** (compras, tarefas) com itens que se riscam por botão.
- **Assinatura paga com Telegram Stars**: mensal (renovação automática) ou anual, com período de teste e preço de fundador.
- **Painel de administração** com entrada por link de uso único enviado pelo bot. Mostra só números agregados, nunca os gastos de cada utilizador. (Por segurança, o código do painel não está neste repositório.)
- **Reporte de erros** pelo próprio bot (`/reportar`, com captura de ecrã), com resposta ao utilizador a partir do painel.
- **Landing page** em pt-BR e pt-PT, com termos e política de privacidade.

## 🛠️ Stack técnica

| Camada | Tecnologia |
|---|---|
| Backend | PHP puro, sem framework |
| Base de dados | MySQL (PDO) |
| IA | Cerebras API (`gpt-oss-120b`) para transformar cada mensagem em JSON estruturado |
| Canal | Telegram Bot API (webhook, botões inline, pagamentos em Stars) |
| Tarefas agendadas | Crons via HTTP (lembretes a cada minuto, resumo diário) |
| Servidor | Linux (Hostinger), publicação via SSH |

## 🧱 Arquitetura

- `webhook.php`: ponto de entrada. Valida o segredo do Telegram e encaminha mensagens e cliques.
- `processar.php`: fluxo de cada mensagem. Comandos, perguntas pendentes e respostas curtas são resolvidos **sem chamar a IA**; só o resto vai ao classificador.
- `classifier.php`: prompt com as intenções e exemplos. Depois da IA, o PHP valida e corrige o resultado: datas impossíveis, dias da semana calculados em código, chaves de JSON corrompidas e palavras reescritas.
- `handlers.php`: regras de negócio, com uma função por intenção.
- `db.php`: base de dados, estado da conversa e envio de mensagens.
- `cron_*.php`: lembretes e resumos. `backup_banco.php`: cópia diária da base de dados fora da pasta pública.
- `migration_*.sql`: evolução do esquema da base de dados, uma migração por funcionalidade.

**Decisões que valeram a pena:** a IA só interpreta a frase, e as contas, datas e validações ficam em PHP, onde podem ser testadas. Isso reduziu erros e custo, porque muitas mensagens nem chegam a passar pela IA.

## 🧪 Testes e publicação

- `testes/`: testes de unidade (cada bloco protege um bug que já aconteceu) e testes de fluxo que simulam conversas completas.
- `ferramentas/deploy.ps1`: corre os testes numa cópia de testes no servidor, faz cópia de segurança, publica e regista a versão em git. Se algum teste falhar, nada é publicado.

## 🔐 Segurança

- As credenciais (token do bot, chave da IA, base de dados, segredo do webhook) ficam num `config.php` fora do git. O modelo está em [`config.example.php`](config.example.php).
- O webhook só aceita pedidos com o cabeçalho secreto registado no Telegram.
- O `.htaccess` bloqueia o acesso direto a logs, SQL e ficheiros internos.

## 👤 O meu papel

Projeto pessoal, desenvolvido com o **Claude Code** como assistente de programação. Defini as funcionalidades e as regras do produto, analisei e modifiquei o código, geri a base de dados, testei cada fluxo e corrigi os erros até tudo funcionar em produção.

---

📫 [LinkedIn](https://www.linkedin.com/in/nicolas-kaiky) · 📍 Lisboa, Portugal
