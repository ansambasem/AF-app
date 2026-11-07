/** The shortest password the server accepts (see AppServiceProvider::boot). */
export const MIN_LENGTH = 8;

export function passwordStrength(password, currentPassword) {
    const checks = [password.length >= MIN_LENGTH, /[A-Za-z\u0600-\u06ff]/.test(password), /\d/.test(password), Boolean(password) && password !== currentPassword];
    let score = password ? Math.min(3, checks.filter(Boolean).length) : 0;
    if (score === 3 && checks.every(Boolean) && (password.length >= 12 || /[^A-Za-z0-9\u0600-\u06ff]/.test(password))) {
        score = 4;
    }
    return { checks, score };
}
