# Microsoft 365 and Outlook mail

FoxDesk connects Microsoft mailboxes with delegated OAuth 2.0 and Microsoft
Graph. Do not enter the mailbox password or an app password into FoxDesk.

## What the connection does

- Mail.ReadWrite reads unread inbox messages, creates or updates tickets, and
  marks handled messages as read.
- Mail.Send sends FoxDesk notifications from the connected mailbox.
- User.Read identifies the mailbox selected during consent.
- offline_access lets FoxDesk renew access without asking the mailbox owner to
  sign in for every background run.

Inbound and outbound use can be switched independently in **Admin > Settings >
Email**. Existing sender allowlists, attachment limits, tenant boundaries, and
email-to-ticket idempotency still apply.

## Register the Microsoft Entra application

1. In Microsoft Entra admin center, open **App registrations > New
   registration**.
2. Choose the supported account type:
   - a single organization for a tenant-specific self-hosted installation;
   - accounts in any organizational directory for a multi-tenant SaaS app.
3. Under **Authentication**, add a **Web** redirect URI. Copy the exact redirect
   URI shown in FoxDesk under **Admin > Settings > Email > Microsoft 365 /
   Outlook**. The URI must match exactly and must use HTTPS outside local
   development.
4. Under **API permissions > Microsoft Graph > Delegated permissions**, add
   User.Read, Mail.ReadWrite, and Mail.Send.
5. Create a client secret under **Certificates & secrets**. Copy its value when
   it is shown; Microsoft will not display it again.
6. Enter the tenant (organizations, common, a tenant UUID, or a verified tenant
   domain), Client ID, and client secret in FoxDesk, then choose **Connect
   Microsoft mailbox**.
7. Sign in as the mailbox that FoxDesk should use and approve the requested
   permissions. Some organizations require an Entra administrator to grant
   consent.

Microsoft documents the delegated authorization-code flow and refresh tokens in
[Get access on behalf of a user](https://learn.microsoft.com/graph/auth-v2-user).
The Mail.Send permission and /me/sendMail endpoint are documented in
[Send mail with Microsoft Graph](https://learn.microsoft.com/graph/api/user-sendmail?view=graph-rest-1.0).

## Server configuration

FoxDesk encrypts the client secret, access token, refresh token, and temporary
PKCE verifier before writing them to the database. Configure a stable
SECRET_KEY, or provide a separate key:

    MICROSOFT_MAIL_ENCRYPTION_KEY=a-long-random-secret-kept-outside-the-repository

The key must remain stable across deploys. Losing or rotating it without a
credential migration requires reconnecting each mailbox.

For SaaS, the Entra application can be configured centrally instead of entering
the same credentials in every workspace:

    MICROSOFT_MAIL_CLIENT_ID=...
    MICROSOFT_MAIL_CLIENT_SECRET=...
    MICROSOFT_MAIL_TENANT_ID=organizations
    MICROSOFT_MAIL_REDIRECT_URI=https://workspace.example.com/index.php?page=microsoft-oauth
    MICROSOFT_MAIL_ENCRYPTION_KEY=...

A centrally configured client ID, secret, tenant, or redirect URI takes
precedence over workspace form values.

## Verify and troubleshoot

1. Use **Test Microsoft connection** in email settings.
2. Send a message from an allowed sender to the connected mailbox.
3. Run php bin/ingest-emails.php or wait for the background scheduler.
4. Confirm that the ticket was created or updated, the message was marked as
   read, and the incoming email log shows the result.
5. Trigger a FoxDesk notification and confirm delivery from the connected
   mailbox and a copy in Sent Items.

If the status changes to reauthorization_required, reconnect the mailbox.
Typical causes are revoked consent, an expired client secret, a disabled
mailbox, or an encryption key that changed between deployments. FoxDesk does
not silently fall back to another outbound provider after a Microsoft Graph
send failure.

