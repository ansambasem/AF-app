import DevelopmentCredits from '@/Components/DevelopmentCredits';
import './AppFooter.css';

const COMPANY = 'شركة أبناء فارس أبو زايد للتجارة والاستيراد';

/**
 * The sign-in screen's footer: the company's rights line and the
 * development team. The signed-in pages carry no footer.
 */
export default function AppFooter() {
    const year = new Date().getFullYear();

    return <footer className="af-login-footer" dir="rtl">
        <p>© <span dir="ltr">{year}</span> {COMPANY}</p>
        <DevelopmentCredits />
    </footer>;
}
