<?php

declare(strict_types=1);

/**
 * Canonical English (en-US) localization catalog. Source of truth for the
 * key set every other locale file must mirror.
 *
 * Operators override individual keys via BAPI; the SDK merges
 *   canonical[locale] ⊕ overrides[locale]
 *   ⊕ canonical[fallback_locale] ⊕ overrides[fallback_locale]
 * at render time.
 *
 * Placeholder syntax is ICU-style `{name}`. `CanonicalSchema::placeholdersIn`
 * extracts those at validate time.
 */
return [
    // Sign-in flow
    'signIn.start.title' => 'Sign in to {applicationName}',
    'signIn.start.subtitle' => 'Welcome back! Please sign in to continue.',
    'signIn.start.actionText' => 'No account?',
    'signIn.start.actionLink' => 'Sign up',
    'signIn.password.title' => 'Enter your password',
    'signIn.password.subtitle' => 'Enter the password associated with your account.',
    'signIn.password.actionLink' => 'Forgot password?',
    'signIn.emailCode.title' => 'Check your email',
    'signIn.emailCode.subtitle' => 'We sent a code to {identifier}. Enter it below.',
    'signIn.emailCode.resendButton' => 'Resend code',
    'signIn.emailLink.title' => 'Check your email',
    'signIn.emailLink.subtitle' => 'Click the link we sent to {identifier} to continue.',
    'signIn.phoneCode.title' => 'Check your phone',
    'signIn.phoneCode.subtitle' => 'We sent a code to {identifier}.',
    'signIn.totp.title' => 'Two-step verification',
    'signIn.totp.subtitle' => 'Enter the code from your authenticator app.',
    'signIn.backupCode.title' => 'Use a backup code',
    'signIn.backupCode.subtitle' => 'Enter one of the backup codes you saved when enabling two-step verification.',
    'signIn.forgotPassword.title' => 'Forgot password?',
    'signIn.forgotPassword.subtitle' => 'Enter your email and we will send a code to reset it.',
    'signIn.resetPassword.title' => 'Reset your password',
    'signIn.resetPassword.subtitle' => 'Choose a new password for your account.',
    'signIn.alternativeMethods.title' => 'Use another method',
    'signIn.alternativeMethods.subtitle' => 'Choose how to sign in.',

    // Sign-up flow
    'signUp.start.title' => 'Create your {applicationName} account',
    'signUp.start.subtitle' => 'Welcome! Please fill in the details to get started.',
    'signUp.start.actionText' => 'Have an account?',
    'signUp.start.actionLink' => 'Sign in',
    'signUp.emailCode.title' => 'Verify your email',
    'signUp.emailCode.subtitle' => 'Enter the verification code we sent to {identifier}.',
    'signUp.phoneCode.title' => 'Verify your phone',
    'signUp.phoneCode.subtitle' => 'Enter the verification code we sent to {identifier}.',
    'signUp.continue.title' => 'Fill in missing fields',
    'signUp.continue.subtitle' => 'A few more details and you are in.',

    // User profile
    'userProfile.start.headerTitle' => 'Account',
    'userProfile.start.headerSubtitle' => 'Manage your account info.',
    'userProfile.start.profileSection.title' => 'Profile',
    'userProfile.start.emailAddressesSection.title' => 'Email addresses',
    'userProfile.start.phoneNumbersSection.title' => 'Phone numbers',
    'userProfile.start.passkeysSection.title' => 'Passkeys',
    'userProfile.start.connectedAccountsSection.title' => 'Connected accounts',
    'userProfile.start.passwordSection.title' => 'Password',
    'userProfile.start.mfaSection.title' => 'Two-step verification',
    'userProfile.start.activeDevicesSection.title' => 'Active devices',
    'userProfile.start.dangerSection.title' => 'Danger zone',
    'userProfile.start.dangerSection.deleteAccountButton' => 'Delete account',

    // User button
    'userButton.action__manageAccount' => 'Manage account',
    'userButton.action__signOut' => 'Sign out',
    'userButton.action__signOutAll' => 'Sign out of all accounts',
    'userButton.action__addAccount' => 'Add account',

    // Organization profile
    'organizationProfile.start.headerTitle' => 'Organization',
    'organizationProfile.start.headerSubtitle' => 'Manage your organization settings.',
    'organizationProfile.membersPage.title' => 'Members',
    'organizationProfile.invitationsPage.title' => 'Invitations',
    'organizationProfile.dangerSection.leaveOrganizationButton' => 'Leave organization',
    'organizationProfile.dangerSection.deleteOrganizationButton' => 'Delete organization',

    // Organization switcher
    'organizationSwitcher.action__createOrganization' => 'Create organization',
    'organizationSwitcher.action__manageOrganization' => 'Manage organization',
    'organizationSwitcher.personalWorkspace' => 'Personal account',
    'organizationSwitcher.notSelected' => 'Select organization',

    // Organization list
    'organizationList.title' => 'Choose an organization',
    'organizationList.subtitle' => 'Pick which organization to continue with.',
    'organizationList.createOrganization' => 'Create organization',

    // Create organization
    'createOrganization.title' => 'Create organization',
    'createOrganization.formButtonSubmit' => 'Create organization',

    // Form-field flat layer (key shape mirrors sdk-react)
    'formFieldLabel__emailAddress' => 'Email address',
    'formFieldLabel__phoneNumber' => 'Phone number',
    'formFieldLabel__password' => 'Password',
    'formFieldLabel__confirmPassword' => 'Confirm password',
    'formFieldLabel__currentPassword' => 'Current password',
    'formFieldLabel__newPassword' => 'New password',
    'formFieldLabel__firstName' => 'First name',
    'formFieldLabel__lastName' => 'Last name',
    'formFieldLabel__username' => 'Username',
    'formFieldLabel__code' => 'Code',
    'formFieldLabel__backupCode' => 'Backup code',
    'formFieldLabel__organizationName' => 'Organization name',
    'formFieldLabel__organizationSlug' => 'Slug',
    'formFieldHint__optional' => 'Optional',
    'formFieldHint__passwordStrength' => 'Use 8 or more characters with a mix of letters and numbers.',
    'formFieldError__notMatchingPasswords' => 'Passwords do not match.',
    'formFieldError__weakPassword' => 'Password is too weak.',
    'formFieldError__invalidCode' => 'Invalid code.',
    'formFieldAction__forgotPassword' => 'Forgot password?',
    'formFieldAction__resendCode' => 'Resend code',
    'formFieldAction__showPassword' => 'Show password',
    'formFieldAction__hidePassword' => 'Hide password',
    'formFieldInputPlaceholder__emailAddress' => 'name@example.com',
    'formFieldInputPlaceholder__phoneNumber' => '+1 555 0100',
    'formFieldInputPlaceholder__code' => '000000',
    'formButtonPrimary' => 'Continue',
    'formButtonReset' => 'Cancel',
    'socialButtonsBlockButton' => 'Continue with {provider}',
    'dividerText' => 'or',
    'footerActionLink__signIn' => 'Sign in',
    'footerActionLink__signUp' => 'Sign up',
    'footerActionLink__useAnotherMethod' => 'Use another method',
    'badge__primary' => 'Primary',
    'badge__verified' => 'Verified',
    'badge__unverified' => 'Unverified',
    'badge__default' => 'Default',
    'badge__you' => 'You',
    'paginationButton__previous' => 'Previous',
    'paginationButton__next' => 'Next',
    'paginationRowText__displaying' => 'Displaying {start}-{end} of {total}',
    'paginationRowText__of' => 'of',
];
