# AWS Deployment Guide — Telegram Support System

This guide deploys the **AWS Lambda + DynamoDB** version of the BitsResources Telegram support bot from the `aws-lambda-dynamodb` branch.

- **Runtime:** Bref PHP 8.4 FPM on AWS Lambda (ARM64).
- **Endpoint:** Lambda Function URL; API Gateway is not used.
- **Database:** DynamoDB tables for support conversations, replies, and student profiles.
- **Region:** Mumbai (`ap-south-1`).
- **Deployment:** GitHub Actions using OpenID Connect (OIDC), not long-lived AWS access keys.

The older PHP/MySQL hosting files remain in the repository for reference. The AWS handler is `public/index.php`.

## Before you begin

You need:
- A Telegram bot token from [@BotFather](https://t.me/BotFather).
- Your numeric Telegram user ID for the admin account.
- An AWS account with permission to create the required deployment resources.
- A GitHub repository with Actions enabled.

Review AWS pricing and create a billing budget/alert before deploying. DynamoDB provisioned capacity, Lambda, CloudWatch Logs, and other services can incur charges. Free-tier and promotional eligibility can change and should not be assumed permanent.

## 1. Configure GitHub repository secrets

Open **Settings → Secrets and variables → Actions → New repository secret** and create:

| Secret | Value |
| --- | --- |
| `AWS_ROLE_ARN` | ARN of the IAM role GitHub Actions will assume through OIDC |
| `TELEGRAM_BOT_TOKEN` | Token from BotFather |
| `TELEGRAM_WEBHOOK_SECRET` | Random secret using only letters, numbers, underscores, or hyphens |
| `ADMIN_TELEGRAM_ID` | Your numeric Telegram user ID, stored as text |

Do not paste these values into source files or commit them. GitHub Actions secrets are available to workflows, not automatically to unrelated hosting services.

## 2. Configure GitHub OIDC in AWS

1. In **AWS IAM → Identity providers**, add `https://token.actions.githubusercontent.com` if it does not already exist. The audience must be `sts.amazonaws.com`.
2. Create an IAM role for web identity using that provider.
3. Restrict the role trust policy to your own repository and deployment branch. For this repository, the subject is:

```text
repo:Abhilash-nallam/Support-System:ref:refs/heads/aws-lambda-dynamodb
```

Example trust policy (replace `YOUR_ACCOUNT_ID` with your 12-digit AWS account ID):

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": {
      "Federated": "arn:aws:iam::YOUR_ACCOUNT_ID:oidc-provider/token.actions.githubusercontent.com"
    },
    "Action": "sts:AssumeRoleWithWebIdentity",
    "Condition": {
      "StringEquals": {
        "token.actions.githubusercontent.com:aud": "sts.amazonaws.com",
        "token.actions.githubusercontent.com:sub": "repo:Abhilash-nallam/Support-System:ref:refs/heads/aws-lambda-dynamodb"
      }
    }
  }]
}
```

The role also needs the permissions required for the Serverless Framework to deploy CloudFormation, Lambda, IAM execution roles/policies, CloudWatch Logs, and DynamoDB resources. Use least privilege where practical; avoid leaving unrestricted `AdministratorAccess` attached permanently. If you fork the project, change the repository and branch in the trust policy.

## 3. Deploy

1. Confirm the four repository secrets and OIDC role are correct.
2. Push a reviewed code change to `aws-lambda-dynamodb`, or open **Actions → Deploy Telegram Support Bot to AWS → Run workflow**.
3. Inspect the workflow logs and confirm the deploy and webhook-registration steps succeed.
4. The workflow deploys Lambda/DynamoDB and registers the resulting Lambda Function URL with Telegram.

The workflow currently runs on pushes to `aws-lambda-dynamodb` and can also be started manually. Documentation-only changes to `README.md` and `README-AWS.md` are ignored by the push trigger.

## 4. Verify the bot

Use a separate Telegram account to test the student flow:

1. Send `/start`.
2. Enter the student's roll number when prompted.
3. Send a test support message.
4. Send another message and verify it is added to the same roll-number conversation.
5. From the configured admin account, test `/tickets`, `/reply ROLL_NUMBER Your reply`, and `/close ROLL_NUMBER`.
6. As the student, test `/status ROLL_NUMBER`.

Do not test with real student data until you have verified the flow.

## Commands and current behavior

**Student commands**
- `/start` — start the bot.
- `/help` — usage guidance.
- `/change_roll` — clear the saved roll number and register again.
- `/status ROLL_NUMBER` — check the status for that roll number.

**Admin commands** (private chat from the configured admin Telegram ID only)
- `/tickets` — list recent conversations.
- `/reply ROLL_NUMBER Your reply` — send a reply to the student's Telegram chat.
- `/close ROLL_NUMBER` — close the conversation.

The current AWS implementation uses the student's roll number as the conversation key. Messages from that roll number are combined in one conversation; it does not issue a unique random ticket ID for each message. The bot accepts text messages. Students must start the bot before Telegram can deliver bot messages to them.

## Troubleshooting

- **GitHub Actions cannot assume the role:** Check `AWS_ROLE_ARN`, the OIDC provider audience, and the trust policy's exact repository/branch subject.
- **Deployment fails:** Read the first failing workflow step. Check IAM permissions and the configured AWS region.
- **Telegram webhook registration fails:** Check `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, and that the deployed Function URL is HTTPS.
- **Function URL returns 403:** Review the Lambda Function URL auth setting and Lambda resource-based permissions for URL invocation. Keep Telegram's webhook secret validation enabled; do not disable webhook validation to work around permission errors.
- **Bot receives updates but cannot send replies:** Check CloudWatch Logs for Telegram API errors and confirm the bot token is valid and the user has started the bot.
- **Bot stops responding:** Check the Lambda logs, Telegram webhook status, workflow outcome, and AWS service/billing status.

Never share bot tokens, webhook secrets, or complete secret-bearing logs publicly.

## Data and deletion warning

The Serverless stack manages DynamoDB tables for conversations, replies, and student profiles. **Deleting the stack or tables may permanently delete support data.** Back up/export the tables before removing infrastructure.

## Security checklist

- Store secrets in GitHub Actions secrets and AWS secret/environment configuration as appropriate.
- Never commit a real token, password, webhook secret, or AWS access key.
- Keep the OIDC trust policy restricted to your own repository and branch.
- Rotate credentials immediately if they are exposed; removing a value from the latest file does not remove it from Git history.
- Do not log or expose student data unnecessarily.
