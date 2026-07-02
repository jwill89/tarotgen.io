<script setup lang="ts"></script>

<template>
  <section class="section">
    <div class="container">
      <h1 class="title is-3 is-size-4-mobile">Privacy Policy</h1>
      <p class="subtitle is-6 has-text-grey">Last updated: June 2, 2025</p>

      <div class="content">
        <h2 class="title is-4">1. Introduction</h2>
        <p>
          Welcome to our tarot reading platform. We respect your privacy and are committed to
          protecting your personal data. This Privacy Policy explains how we collect, use, and
          safeguard your information when you use our service.
        </p>

        <h2 class="title is-4">2. Information We Collect</h2>
        <h3 class="title is-5">Account Registration</h3>
        <p>When you create an account, we collect:</p>
        <ul>
          <li>
            <strong>Email address</strong> — used solely for account identification, login, and
            essential account-related communications (e.g., password resets, account activation).
          </li>
        </ul>

        <h3 class="title is-5">Google Sign-In</h3>
        <p>If you choose to register or log in with Google, we receive and store:</p>
        <ul>
          <li><strong>Email address</strong> — used for account identification.</li>
          <li><strong>Display name</strong> — used as your default username.</li>
        </ul>
        <p>We do not access any other Google account data, contacts, files, or activity.</p>

        <h3 class="title is-5">Readings &amp; Usage Data</h3>
        <p>
          If you are logged in, your tarot readings and any notes you add are stored in your account
          so you can access them later. We do not share your readings with anyone.
        </p>

        <h2 class="title is-4">3. How We Use Your Information</h2>
        <p>We use the information we collect exclusively to:</p>
        <ul>
          <li>Create and manage your account.</li>
          <li>Authenticate you when you log in.</li>
          <li>Send essential account-related emails (activation, password reset).</li>
          <li>Store and display your saved readings and notes.</li>
        </ul>
        <p>
          We do <strong>not</strong> use your data for advertising, analytics profiling, or
          marketing emails. We will never sell or rent your personal information to third parties.
        </p>

        <h2 class="title is-4">4. Data Storage &amp; Security</h2>
        <p>
          Your data is stored securely on our servers. Passwords are hashed using industry-standard
          algorithms and are never stored in plain text. We implement reasonable technical and
          organizational measures to protect your personal data against unauthorized access,
          alteration, or destruction.
        </p>

        <h2 class="title is-4">5. Data Sharing</h2>
        <p>
          We do not share your personal information with any third parties, except where required by
          law. We do not use third-party analytics or advertising services that would receive your
          data.
        </p>

        <h2 class="title is-4">6. Cookies</h2>
        <p>
          We use only essential cookies (or local storage) required for authentication and session
          management. We do not use tracking cookies or third-party cookies.
        </p>

        <h2 class="title is-4">7. Your Rights</h2>
        <p>You have the right to:</p>
        <ul>
          <li>Access the personal data we hold about you.</li>
          <li>Request correction of inaccurate data.</li>
          <li>Request deletion of your account and associated data.</li>
          <li>Withdraw consent for Google sign-in at any time by unlinking your account.</li>
        </ul>
        <p>
          To exercise any of these rights, please contact us through our
          <router-link :to="{ name: 'contact' }">contact form</router-link>.
        </p>

        <h2 class="title is-4">8. Data Retention</h2>
        <p>
          We retain your account data for as long as your account is active. If you delete your
          account, all associated personal data and readings will be permanently removed from our
          systems within 30 days.
        </p>

        <h2 class="title is-4">9. Children's Privacy</h2>
        <p>
          Our service is not directed to children under the age of 13. We do not knowingly collect
          personal information from children. If you believe a child has provided us with personal
          data, please contact us so we can remove it.
        </p>

        <h2 class="title is-4">10. Changes to This Policy</h2>
        <p>
          We may update this Privacy Policy from time to time. Any changes will be posted on this
          page with an updated revision date. Continued use of the service after changes constitutes
          acceptance of the revised policy.
        </p>

        <h2 class="title is-4">11. Contact Us</h2>
        <p>
          If you have any questions about this Privacy Policy, please reach out via our
          <router-link :to="{ name: 'contact' }">contact form</router-link>.
        </p>
      </div>
    </div>
  </section>
</template>
