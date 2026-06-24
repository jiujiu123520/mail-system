/* Web 邮件前端 */

const { createApp, ref, reactive, onMounted, watch, onUnmounted, nextTick } = Vue;
const API = '/api';

// 设备指纹生成
function generateFingerprint() {
    const keys = [
        navigator.userAgent,
        navigator.language,
        screen.width + 'x' + screen.height,
        screen.colorDepth,
        new Date().getTimezoneOffset(),
        navigator.hardwareConcurrency || 'unknown',
        navigator.platform,
    ];
    let hash = 0;
    const str = keys.join('|');
    for (let i = 0; i < str.length; i++) {
        const ch = str.charCodeAt(i);
        hash = ((hash << 5) - hash) + ch;
        hash |= 0;
    }
    return 'fp_' + Math.abs(hash).toString(16);
}

async function http(url, options = {}) {
    const r = await fetch(API + url, {
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        ...options,
    });
    const j = await r.json();
    if (j.code !== 0) throw new Error(j.message);
    return j.data;
}

createApp({
    setup() {
        const loggedIn = ref(false);
        const logging = ref(false);
        const loginError = ref('');
        const loginForm = reactive({ address: '', password: '' });
        const user = ref({});
        const folders = [
            { key: 'INBOX', name: '收件箱', icon: 'fa-inbox' },
            { key: 'SENT',  name: '已发送', icon: 'fa-paper-plane' },
            { key: 'DRAFTS',name: '草稿箱', icon: 'fa-file-text-o' },
            { key: 'TRASH', name: '已删除', icon: 'fa-trash' },
        ];
        const folder = ref('INBOX');
        const currentFolderName = ref('收件箱');
        const emails = ref({ list: [], total: 0 });
        const currentPage = ref(1);
        const pageSize = ref(20); // Default page size
        const current = ref(null);
        const isConversationView = ref(false);
        const composer = reactive({ show: false, to: '', subject: '', body_html: '', sending: false, editor: null });

        // API 密钥管理
        const showApiKeyManager = ref(false);
        const apiKeys = ref([]);
        const newApiKeyForm = reactive({ name: '', secret_key: '', expires_at: null, whitelist_ips: [] });
        const showEditApiKeyDialog = ref(false);
        const editApiKeyForm = reactive({ id: null, name: '', access_key: '', secret_key: '', expires_at: null, whitelist_ips: [] });

        // 修改密码
        const showChangePasswordDialog = ref(false);
        const changePasswordForm = reactive({ old_password: '', new_password: '', confirm_password: '' });

        // 注册相关
        const showRegister = ref(false);
        const registerForm = reactive({ username: '', password: '', email: '', domain: '', captcha_key: '', captcha_code: '', captcha_svg: '' });
        const registering = ref(false);
        const registerError = ref('');
        const allowRegistration = ref(false);
        const requireCaptcha = ref(true);
        const allowedRegistrationDomains = ref([]);

        // 设备指纹
        const fingerprint = ref('');

        // 无操作超时 (30分钟)
        const SESSION_TIMEOUT = 30 * 60 * 1000; // 30分钟
        const IDLE_WARNING = 28 * 60 * 1000; // 28分钟开始警告
        let lastActivity = Date.now();
        let idleTimer = null;
        let warningTimer = null;
        const showIdleWarning = ref(false);
        const idleSeconds = ref(120);

        const resetActivity = () => {
            lastActivity = Date.now();
            showIdleWarning.value = false;
            idleSeconds.value = 120;
        };

        const checkIdle = () => {
            const elapsed = Date.now() - lastActivity;
            if (elapsed >= SESSION_TIMEOUT) {
                doLogout();
                return;
            }
            if (elapsed >= IDLE_WARNING && !showIdleWarning.value) {
                showIdleWarning.value = true;
                idleSeconds.value = Math.ceil((SESSION_TIMEOUT - elapsed) / 1000);
                const countdown = setInterval(() => {
                    idleSeconds.value--;
                    if (idleSeconds.value <= 0 || !showIdleWarning.value) {
                        clearInterval(countdown);
                    }
                }, 1000);
            }
        };

        const extendSession = () => {
            resetActivity();
            showIdleWarning.value = false;
        };

        // 注册
        const loadCaptcha = async () => {
            try {
                const data = await http('/auth/captcha');
                registerForm.captcha_key = data.key;
                registerForm.captcha_svg = data.svg;
            } catch (e) {
                console.error('加载验证码失败:', e);
            }
        };

        const doRegister = async () => {
            if (!registerForm.username || !registerForm.password) {
                registerError.value = '请填写用户名和密码';
                return;
            }
            if (registerForm.password.length < 6) {
                registerError.value = '密码至少6位';
                return;
            }
            if (requireCaptcha.value && !registerForm.captcha_code) {
                registerError.value = '请输入验证码';
                return;
            }
            registering.value = true;
            registerError.value = '';
            try {
                // 密码直接发送，后端负责哈希
                await http('/auth/register', {
                    method: 'POST',
                    body: JSON.stringify({
                        username: registerForm.username,
                        password: registerForm.password,
                        email: registerForm.email,
                        domain: registerForm.domain,
                        captcha_key: registerForm.captcha_key,
                        captcha_code: registerForm.captcha_code,
                        fingerprint: fingerprint.value,
                    }),
                });
                ElementPlus.ElMessage.success('注册成功，请登录');
                showRegister.value = false;
                loginForm.address = registerForm.username;
                loginForm.password = '';
            } catch (e) {
                registerError.value = e.message;
                if (requireCaptcha.value) loadCaptcha();
            } finally {
                registering.value = false;
            }
        };

        // API Key 管理
        const loadApiKeys = async () => {
            try {
                const data = await http('/api-keys');
                apiKeys.value = data.list;
            } catch (e) {
                ElementPlus.ElMessage.error('加载 API 密钥失败: ' + e.message);
            }
        };

        const generateApiKey = async () => {
            if (!newApiKeyForm.name) {
                ElementPlus.ElMessage.error('请输入密钥名称');
                return;
            }
            try {
                await http('/api-keys', {
                    method: 'POST',
                    body: JSON.stringify({
                        name: newApiKeyForm.name,
                        secret_key: newApiKeyForm.secret_key || undefined,
                        expires_at: newApiKeyForm.expires_at ? new Date(newApiKeyForm.expires_at).toISOString() : undefined,
                        whitelist_ips: newApiKeyForm.whitelist_ips,
                    }),
                });
                ElementPlus.ElMessage.success('API 密钥生成成功');
                newApiKeyForm.name = '';
                newApiKeyForm.secret_key = '';
                newApiKeyForm.expires_at = null;
                newApiKeyForm.whitelist_ips = [];
                loadApiKeys();
            } catch (e) {
                ElementPlus.ElMessage.error('生成 API 密钥失败: ' + e.message);
            }
        };

        const editApiKey = (row) => {
            editApiKeyForm.id = row.id;
            editApiKeyForm.name = row.name;
            editApiKeyForm.access_key = row.access_key;
            editApiKeyForm.secret_key = row.secret_key; // This will be a placeholder, actual secret is not returned
            editApiKeyForm.expires_at = row.expires_at ? new Date(row.expires_at) : null;
            editApiKeyForm.whitelist_ips = row.whitelist_ips || [];
            showEditApiKeyDialog.value = true;
        };

        const updateApiKey = async () => {
            try {
                await http('/api-keys/' + editApiKeyForm.id, {
                    method: 'PUT',
                    body: JSON.stringify({
                        expires_at: editApiKeyForm.expires_at ? new Date(editApiKeyForm.expires_at).toISOString() : null,
                        whitelist_ips: editApiKeyForm.whitelist_ips,
                    }),
                });
                ElementPlus.ElMessage.success('API 密钥更新成功');
                showEditApiKeyDialog.value = false;
                loadApiKeys();
            } catch (e) {
                ElementPlus.ElMessage.error('更新 API 密钥失败: ' + e.message);
            }
        };

        const deleteApiKey = async (row) => {
            try { await ElementPlus.ElMessageBox.confirm('确认删除此 API 密钥？', '提示', { type: 'warning' }); } catch (_) { return; }
            try {
                await http('/api-keys/' + row.id, { method: 'DELETE' });
                ElementPlus.ElMessage.success('API 密钥已删除');
                loadApiKeys();
            } catch (e) {
                ElementPlus.ElMessage.error('删除 API 密钥失败: ' + e.message);
            }
        };

        // 修改密码
        const doChangePassword = async () => {
            if (!changePasswordForm.old_password || !changePasswordForm.new_password || !changePasswordForm.confirm_password) {
                ElementPlus.ElMessage.error('请填写所有密码字段');
                return;
            }
            if (changePasswordForm.new_password.length < 6) {
                ElementPlus.ElMessage.error('新密码至少6位');
                return;
            }
            if (changePasswordForm.new_password !== changePasswordForm.confirm_password) {
                ElementPlus.ElMessage.error('两次输入的新密码不一致');
                return;
            }

            try {
                await http('/user/change-password', {
                    method: 'POST',
                    body: JSON.stringify({
                        old_password: changePasswordForm.old_password,
                        new_password: changePasswordForm.new_password,
                    }),
                });
                ElementPlus.ElMessage.success('密码修改成功，请重新登录');
                showChangePasswordDialog.value = false;
                doLogout(); // Force re-login after password change
            } catch (e) {
                ElementPlus.ElMessage.error('修改密码失败: ' + e.message);
            }
        };

        const selectFolder = (k) => {
            folder.value = k;
            currentFolderName.value = folders.find(f => f.key === k).name;
            current.value = null;
            currentPage.value = 1; // Reset page when changing folder
            loadEmails();
            resetActivity();
        };

        const loadEmails = async () => {
            const offset = (currentPage.value - 1) * pageSize.value;
            const data = await http(`/mailboxes/${user.value.id}/emails?folder=${folder.value}&limit=${pageSize.value}&offset=${offset}`);
            emails.value = data;
        };

        const handlePageChange = (page) => {
            currentPage.value = page;
            loadEmails();
        };

        const openEmail = async (e) => {
            let data;
            if (e.conversation_id) {
                data = await http('/emails/' + e.id + '?conversation=true');
                current.value = data.conversation;
                isConversationView.value = true;
            } else {
                data = await http('/emails/' + e.id);
                current.value = data;
                isConversationView.value = false;
            }
            e.is_read = 1;
            resetActivity();
        };

        const deleteEmail = async (e) => {
            try { await ElementPlus.ElMessageBox.confirm('确认删除？', '提示', { type: 'warning' }); } catch (_) { return; }
            await http('/emails/' + e.id, { method: 'DELETE' });
            ElementPlus.ElMessage.success('已删除');
            current.value = null;
            loadEmails();
            resetActivity();
        };

        const compose = () => {
            composer.to = ''; composer.subject = ''; composer.body_html = '';
            composer.show = true;
            resetActivity();
            // 初始化 TinyMCE
            nextTick(() => {
                if (composer.editor) {
                    composer.editor.setContent('');
                    return;
                }
                tinymce.init({
                    selector: '#tinymce-editor',
                    height: 200,
                    menubar: false,
                    plugins: [
                        'advlist autolink lists link image charmap print preview anchor',
                        'searchreplace visualblocks code fullscreen',
                        'insertdatetime media table paste code help wordcount'
                    ],
                    toolbar: 'undo redo | formatselect | ' +
                        'bold italic backcolor | alignleft aligncenter ' +
                        'alignright alignjustify | bullist numlist outdent indent | ' +
                        'removeformat | help',
                    setup: function (editor) {
                        editor.on('init', function () {
                            composer.editor = editor;
                            editor.setContent(composer.body_html);
                        });
                        editor.on('change', function () {
                            composer.body_html = editor.getContent();
                        });
                    }
                });
            });
        };

        const sendEmail = async () => {
            if (!composer.to) return ElementPlus.ElMessage.error('请输入收件人');
            composer.sending = true;
            try {
                const response = await http('/emails/send', {
                    method: 'POST',
                    body: JSON.stringify({
                        from_mailbox_id: user.value.id,
                        to: composer.to.split(',').map(s => s.trim()).filter(Boolean),
                        subject: composer.subject,
                        body_html: composer.editor ? composer.editor.getContent() : '',
                        body_text: composer.editor ? composer.editor.getContent({ format: 'text' }) : '',
                    }),
                });
                if (response.failed && response.failed.length > 0) {
                    let errorMessage = '邮件已发送，但部分收件人投递失败：<br>';
                    response.failed.forEach(f => {
                        errorMessage += `${f.address}: ${f.reason}<br>`;
                    });
                    ElementPlus.ElMessage.warning({
                        dangerouslyUseHTMLString: true,
                        message: errorMessage,
                        duration: 5000,
                    });
                } else {
                    ElementPlus.ElMessage.success('发送成功');
                }
                composer.show = false;
                if (folder.value === 'SENT') loadEmails();
            } catch (e) {
                ElementPlus.ElMessage.error(e.message);
            } finally {
                composer.sending = false;
            }
            resetActivity();
        };

        const doLogin = async () => {
            logging.value = true;
            loginError.value = '';
            try {
                // 密码直接发送，后端负责哈希和验证
                const r = await fetch(API + '/webmail/login', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        address: loginForm.address,
                        password: loginForm.password,
                        fingerprint: fingerprint.value,
                    }),
                });
                const j = await r.json();
                if (j.code !== 0) throw new Error(j.message);
                user.value = j.data.mailbox;
                loggedIn.value = true;
                // 启动无操作检测
                startIdleTimer();
                loadEmails();
            } catch (e) {
                loginError.value = e.message;
            } finally {
                logging.value = false;
            }
        };

        const doLogout = async () => {
            stopIdleTimer();
            try { await http('/webmail/logout', { method: 'POST' }); } catch (e) {}
            loggedIn.value = false;
            showIdleWarning.value = false;
        };

        const startIdleTimer = () => {
            stopIdleTimer();
            // 监听用户活动
            ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(evt => {
                document.addEventListener(evt, resetActivity, { passive: true });
            });
            // 每分钟检查一次
            idleTimer = setInterval(checkIdle, 60000);
        };

        const stopIdleTimer = () => {
            if (idleTimer) { clearInterval(idleTimer); idleTimer = null; }
            if (warningTimer) { clearInterval(warningTimer); warningTimer = null; }
            ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(evt => {
                document.removeEventListener(evt, resetActivity);
            });
        };

        onMounted(async () => {
            // 生成设备指纹
            fingerprint.value = generateFingerprint();

            // 检查公开设置
            try {
                const pub = await http('/setting/public');
                allowRegistration.value = pub.allow_registration == '1';
                requireCaptcha.value = pub.require_captcha !== '0';
                allowedRegistrationDomains.value = pub.allowed_registration_domains || [];
            } catch (e) {}

            // 检查是否已登录
            try {
                const me = await http('/webmail/me');
                user.value = me;
                loggedIn.value = true;
                startIdleTimer();
                loadEmails();
            } catch (e) {}
        });

        onUnmounted(() => {
            stopIdleTimer();
        });

        watch(() => composer.show, (newVal) => {
            if (!newVal && composer.editor) {
                composer.editor.destroy();
                composer.editor = null;
            }
        });

        watch(() => showApiKeyManager.value, (newVal) => {
            if (newVal) {
                loadApiKeys();
            }
        });

        return {
            loggedIn, logging, loginError, loginForm, user,
            folders, folder, currentFolderName, emails, current,
            composer, isConversationView,
            showRegister, registerForm, registering, registerError,
            allowRegistration, requireCaptcha, allowedRegistrationDomains,
            showIdleWarning, idleSeconds,
            selectFolder, loadEmails, openEmail, deleteEmail, compose, sendEmail,
            doLogin, doLogout, loadCaptcha, doRegister, extendSession,
            currentPage, pageSize, handlePageChange,
            showApiKeyManager, apiKeys, newApiKeyForm, generateApiKey, editApiKey, updateApiKey, deleteApiKey, showEditApiKeyDialog, editApiKeyForm,
            showChangePasswordDialog, changePasswordForm, doChangePassword,
        };
    }
}).use(ElementPlus).mount('#app');
