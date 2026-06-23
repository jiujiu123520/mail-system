/* Web 邮件前端 */

const { createApp, ref, reactive, onMounted, watch, onUnmounted } = Vue;
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
        const current = ref(null);
        const composer = reactive({ show: false, to: '', subject: '', body: '', sending: false });

        // 注册相关
        const showRegister = ref(false);
        const registerForm = reactive({ username: '', password: '', email: '', captcha_key: '', captcha_code: '', captcha_svg: '' });
        const registering = ref(false);
        const registerError = ref('');
        const allowRegistration = ref(false);
        const requireCaptcha = ref(true);

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
                // 前端对密码进行SHA256加密
                let pwd = registerForm.password;
                if (!/^[a-f0-9]{64}$/i.test(registerForm.password)) {
                    pwd = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(registerForm.password)).then(buf => Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join(''));
                }
                await http('/auth/register', {
                    method: 'POST',
                    body: JSON.stringify({
                        username: registerForm.username,
                        password: pwd,
                        email: registerForm.email,
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

        const selectFolder = (k) => {
            folder.value = k;
            currentFolderName.value = folders.find(f => f.key === k).name;
            current.value = null;
            loadEmails();
            resetActivity();
        };

        const loadEmails = async () => {
            const data = await http('/mailboxes/' + user.value.id + '/emails?folder=' + folder.value + '&limit=100');
            emails.value = data;
        };

        const openEmail = async (e) => {
            const data = await http('/emails/' + e.id);
            current.value = data;
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
            composer.to = ''; composer.subject = ''; composer.body = '';
            composer.show = true;
            resetActivity();
        };

        const sendEmail = async () => {
            if (!composer.to) return ElementPlus.ElMessage.error('请输入收件人');
            composer.sending = true;
            try {
                await http('/emails/send', {
                    method: 'POST',
                    body: JSON.stringify({
                        from_mailbox_id: user.value.id,
                        to: composer.to.split(',').map(s => s.trim()).filter(Boolean),
                        subject: composer.subject,
                        body_text: composer.body,
                    }),
                });
                ElementPlus.ElMessage.success('发送成功');
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
                // 前端对密码进行SHA256加密
                let pwd = loginForm.password;
                if (!/^[a-f0-9]{64}$/i.test(loginForm.password)) {
                    pwd = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(loginForm.password)).then(buf => Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join(''));
                }
                const r = await fetch(API + '/webmail/login', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        address: loginForm.address,
                        password: pwd,
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

        return {
            loggedIn, logging, loginError, loginForm, user,
            folders, folder, currentFolderName, emails, current,
            composer,
            showRegister, registerForm, registering, registerError,
            allowRegistration, requireCaptcha,
            showIdleWarning, idleSeconds,
            selectFolder, loadEmails, openEmail, deleteEmail, compose, sendEmail,
            doLogin, doLogout, loadCaptcha, doRegister, extendSession,
        };
    }
}).use(ElementPlus).mount('#app');
