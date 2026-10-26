# AWS deployment: Telegram Support Bot (PHP + Lambda + DynamoDB)

This branch adds an AWS version of the existing PHP Telegram support bot. Existing InfinityFree files and the original README remain unchanged.

## Architecture

- AWS Lambda with Bref PHP 8.4 FPM runtime, ARM64, 256 MB memory.
- Lambda Function URL (no API Gateway).
- DynamoDB stores tickets and replies; no always-running RDS instance.
- GitHub Actions deploys this branch using AWS OIDC; no long-lived AWS access keys are needed.
- Region: Mumbai (`ap-south-1`).

## Cost and data

DynamoDB tables use provisioned capacity at 5 read and 5 write units each to keep a small low-volume bot predictable. Check the Free Tier/credits and current regional pricing before deployment. Lambda, DynamoDB, CloudWatch Logs, internet data transfer and other services may incur costs beyond allowances. Set a billing budget/alert first. AWS Free Plan access is time-limited and is not permanent free hosting.

Tickets and replies are stored only in DynamoDB. Deleting the CloudFormation stack can delete the tables and their data. Export/back up data before deleting resources.

## GitHub repository secrets

On the repository, open Settings → Secrets and variables → Actions and add:

- `AWS_ROLE_ARN`: ARN of an IAM role configured for GitHub OIDC and restricted to this repository and this branch.
- `TELEGRAM_BOT_TOKEN`: bot token from BotFather. If it was exposed, revoke it and use a replacement.
- `TELEGRAM_WEBHOOK_SECRET`: random 32–64 character secret using only letters, digits, underscore or hyphen.
- `ADMIN_TELEGRAM_ID`: numeric Telegram user ID, stored as text.

Never commit secrets into files.

## IAM role setup

1. In AWS IAM → Identity providers, add `https://token.actions.githubusercontent.com` as an OpenID Connect provider with audience `sts.amazonaws.com` if it does not already exist.
2. Create an IAM role for Web identity using that provider.
3. Restrict the trust policy to this repo and branch. Replace `YOUR_ACCOUNT_ID` with your 12-digit AWS account ID:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": {"Federated": "arn:aws:iam::YOUR_ACCOUNT_ID:oidc-provider/token.actions.githubusercontent.com"},
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

The role also needs deployment permissions for CloudFormation, Lambda, IAM execution roles/policies, CloudWatch Logs, and DynamoDB. Avoid permanently granting unrestricted AdministratorAccess. Review the required permissions before deploying.

## Deploy

1. Add the four GitHub secrets.
2. Push a commit to branch `aws-lambda-dynamodb` or manually run the workflow from GitHub Actions.
3. Open Actions → **Deploy Telegram Support Bot to AWS** and review logs.
4. The workflow deploys Lambda and DynamoDB, then registers the Lambda Function URL as the Telegram webhook.
5. Test `/start`, submit a ticket from a second Telegram account, then test admin `/tickets`, `/reply TICKET_ID message`, and `/close TICKET_ID`.

Do not deploy until the OIDC role and secrets are configured.

## Existing bot behavior

- Text messages from users create tickets.
- Admin commands: `/tickets`, `/reply BR-YYYYMMDD-ABC123 message`, `/close BR-YYYYMMDD-ABC123`.
- Users can use `/status BR-YYYYMMDD-ABC123`; the bot verifies ticket ownership by Telegram chat ID.
- The webhook validates Telegram's `X-Telegram-Bot-Api-Secret-Token` header.
- Text messages only; each user text message creates a separate ticket.
