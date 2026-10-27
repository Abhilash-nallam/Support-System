# BitsResources Telegram Support System

A PHP Telegram support bot for BitsResources, deployed on **AWS Lambda + DynamoDB**. Students contact support through Telegram; the admin can view conversations, reply, and close support records.

> **Deployment branch:** `aws-lambda-dynamodb`  
> **AWS region:** Mumbai (`ap-south-1`)  
> **Detailed AWS setup:** See [README-AWS.md](README-AWS.md).

## Features

- Telegram webhook handled by PHP on AWS Lambda (Bref runtime).
- DynamoDB stores student profiles, support conversations, and replies.
- Students register their roll number before sending a support issue.
- Messages from the same roll number are combined into one conversation.
- Admin-only commands to list conversations, reply, and close them.
- Webhook requests are validated with Telegram's secret-token header.
- GitHub Actions deploys through AWS OIDC; no long-lived AWS access key is required.

## How it works

1. A student opens the bot and sends `/start`.
2. The student sends their roll number when prompted.
3. The student sends a support message. The bot records it and notifies the admin.
4. Further messages from that roll number are added to the same conversation.
5. The admin replies from Telegram; the student receives the reply in the bot chat.

**Current ticket identifier:** the student's roll number. This implementation does not create a separate random ticket ID for every message.

## Requirements

- A Telegram bot created with [@BotFather](https://t.me/BotFather).
- An AWS account with permission to create/deploy Lambda, DynamoDB, CloudFormation, IAM roles, and CloudWatch Logs resources.
- A GitHub repository with Actions enabled.
- An AWS IAM role configured for GitHub Actions OIDC.
- PHP 8.4-compatible Bref runtime and the dependencies declared in `composer.json` (installed by the workflow).

## Quick setup

For a full, step-by-step guide—including the OIDC trust policy and deployment details—follow **[README-AWS.md](README-AWS.md)**. In summary:

1. Fork or clone this repository.
2. Configure an AWS IAM OIDC identity provider and a role restricted to your own repository and deployment branch.
3. Add the required repository secrets under **Settings → Secrets and variables → Actions**:
   - `AWS_ROLE_ARN`
   - `TELEGRAM_BOT_TOKEN`
   - `TELEGRAM_WEBHOOK_SECRET`
   - `ADMIN_TELEGRAM_ID`
4. Review the IAM permissions and AWS costs before deploying.
5. Push your reviewed changes to your deployment branch or manually run **Deploy Telegram Support Bot to AWS** from the Actions tab.
6. Test the bot with a separate student Telegram account.

Do not copy the example OIDC trust policy unchanged: replace the repository and branch subject with your own values. Never put real tokens, passwords, or AWS credentials in source files.

## Telegram commands

### Students

| Command | Purpose |
| --- | --- |
| `/start` | Start the bot and see the next step |
| `/help` | Show usage guidance |
| `/change_roll` | Reset the saved roll number and register again |
| `/status ROLL_NUMBER` | Check the conversation status for the registered roll number |

### Admin

| Command | Purpose |
| --- | --- |
| `/tickets` | Show recent conversations |
| `/reply ROLL_NUMBER Your reply` | Send a reply to that student's Telegram chat |
| `/close ROLL_NUMBER` | Close the conversation |

Use the student's actual roll number in admin commands. The bot only accepts admin commands from the configured admin Telegram ID in a private chat.

## Repository layout

- `public/index.php` — AWS Lambda webhook and support-bot logic.
- `serverless.yml` — Lambda, Function URL, DynamoDB tables, and runtime configuration.
- `.github/workflows/deploy-aws.yml` — GitHub Actions deployment workflow.
- `scripts/register-webhook.php` — registers the deployed Lambda Function URL with Telegram.
- `composer.json` — PHP dependencies.
- `README-AWS.md` — detailed AWS deployment and IAM setup.
- `webhook.php`, `db.php`, `telegram.php`, `schema.sql`, and related files — earlier PHP/MySQL hosting version retained for reference; they are not the AWS Lambda handler.

## Security notes

- Keep all real credentials in GitHub Actions secrets or the relevant hosting provider's secret store.
- Never commit a real bot token, webhook secret, database password, or AWS access key.
- Restrict the GitHub OIDC trust policy to your own repository and branch.
- Do not print secrets in workflow logs.
- If a credential has ever been committed or exposed, rotate it; deleting the file alone does not remove it from Git history.
- Protect the bot from unauthorized webhook calls by keeping the webhook secret private.
- Avoid sending student passwords, OTPs, or other sensitive information through support messages.

## Costs and data

AWS usage may incur charges depending on current pricing, account eligibility, and usage. Configure a billing budget/alert before deploying. DynamoDB data may be deleted if the CloudFormation stack or tables are deleted; back up data before removing resources.

## Contributing

Fork the repository, make changes on a separate branch, and test them with a test bot and AWS resources before deploying to a live bot. Do not test destructive infrastructure changes against production data.

## License

See [LICENSE](LICENSE).
