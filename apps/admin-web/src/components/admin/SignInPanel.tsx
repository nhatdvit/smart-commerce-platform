'use client';

import { useState } from 'react';

import { useAdminData } from './AdminDataProvider';

/**
 * The sign-in panel, shown in place of the dashboard when there is no session.
 *
 * Deliberately plain: this is the smallest thing that makes the staff screens
 * reachable, not a designed login experience. Password reset, remember-me and
 * the rest belong to the Identity milestone.
 *
 * The password is submitted and forgotten - never stored, never logged, and
 * never put in a URL (CLAUDE.md section 8).
 */
export function SignInPanel() {
  const { signIn } = useAdminData();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);

  /**
   * Submits without handing a promise to the DOM.
   *
   * `onSubmit` expects a void return; giving it an async function means a
   * rejection would become an unhandled one, so the work is started here and
   * its failure is reported through the toast the provider already owns.
   */
  function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    void signIn(email, password).finally(() => setBusy(false));
  }

  return (
    <div className="signin-shell">
      <form className="panel signin-card" onSubmit={submit}>
        <h1>Bếp nhà mình</h1>
        <p>Đăng nhập để quản lý thực đơn và đơn hàng.</p>

        <label htmlFor="signin-email">Email</label>
        <input
          id="signin-email"
          type="email"
          autoComplete="username"
          required
          value={email}
          onChange={(event) => setEmail(event.target.value)}
        />

        <label htmlFor="signin-password">Mật khẩu</label>
        <input
          id="signin-password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />

        <button type="submit" className="btn btn-primary" disabled={busy}>
          {busy ? 'Đang đăng nhập...' : 'Đăng nhập'}
        </button>
      </form>
    </div>
  );
}
