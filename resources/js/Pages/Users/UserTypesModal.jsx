import ClassificationChips from '@/Components/ClassificationChips';
import DialogFrame from '@/Components/DialogFrame';
import SecondaryButton from '@/Components/SecondaryButton';

export default function UserTypesModal({ show, onClose, items, canCreate, onDelete, onSaved }) {
    return <DialogFrame show={show} onClose={onClose} title="أنواع المستخدمين" icon="tag" tone="blue" maxWidth="4xl"
        description="مسميات وظيفية لتنظيم الحسابات والتقارير. الصلاحيات تُحدّد لكل مستخدم بشكل مستقل. العدد يشمل الحسابات التي تملك صلاحية رؤيتها."
        footer={<SecondaryButton onClick={onClose}>إغلاق</SecondaryButton>}>
        <ClassificationChips
            title="أنواع المستخدمين"
            showHeader={false}
            items={items}
            baseUrl="/user-types"
            canCreate={canCreate}
            countLabel="مستخدم"
            addLabel="نوع مستخدم جديد"
            inputLabel="اسم نوع المستخدم"
            emptyLabel="لا توجد أنواع مستخدمين بعد."
            maxLength={255}
            onDelete={onDelete}
            visitOptions={{ onSuccess: onSaved }}
        />
    </DialogFrame>;
}
