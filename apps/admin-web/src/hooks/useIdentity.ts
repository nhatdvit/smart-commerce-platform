'use client';

import { useEffect, useState } from 'react';

import { useAdminData } from '@/components/admin/AdminDataProvider';
import { loadIdentity, type StaffIdentity } from '@/lib/session-api';

/**
 * The signed-in staff member, or null until the answer arrives.
 *
 * Keyed to the shell's session state, not to mount. Signing in does not
 * reload the page - the shell swaps the sign-in panel for the dashboard in
 * place - so a hook that asked once on mount would still be holding the
 * answer `/me` gave while nobody was signed in, and the screens that ask it
 * whether to show money would go on hiding it from a manager who had just
 * signed in.
 *
 * Null is also the answer when the read fails, and every caller treats it the
 * same way: greet nobody by name, and offer only what everyone may do.
 * Failing closed is the right default for a function whose other job is
 * deciding whether to show money.
 */
export function useIdentity(): StaffIdentity | null {
  const { session } = useAdminData();
  const [identity, setIdentity] = useState<StaffIdentity | null>(null);

  useEffect(() => {
    if (session !== 'signed-in') {
      setIdentity(null);

      return;
    }

    let active = true;

    loadIdentity()
      .then((value) => {
        if (active) {
          setIdentity(value);
        }
      })
      .catch(() => {
        // Resolved, but anonymous: a read that did not answer must not leave
        // the screen waiting forever, and must not be read as "this person
        // may see money" either.
        if (active) {
          setIdentity({ name: '', email: '', role: null, initials: '' });
        }
      });

    return () => {
      active = false;
    };
  }, [session]);

  return identity;
}
