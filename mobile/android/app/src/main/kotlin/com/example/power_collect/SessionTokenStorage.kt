package com.example.power_collect

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.io.IOException
import java.security.GeneralSecurityException
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class SessionTokenStorage(context: Context) {
    private val preferences = context.getSharedPreferences("session_vault", Context.MODE_PRIVATE)
    private val alias = "powercollect.session.v1"

    private fun key(create: Boolean): SecretKey? {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(alias, null) as? SecretKey)?.let { return it }
        if (!create) return null
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build())
        }.generateKey()
    }

    fun write(token: String) {
        require(token.isNotBlank() && token.length <= 512)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key(true))
        val encrypted = cipher.doFinal(token.toByteArray(Charsets.UTF_8))
        val value = "v1.${Base64.encodeToString(cipher.iv, Base64.NO_WRAP)}.${Base64.encodeToString(encrypted, Base64.NO_WRAP)}"
        if (!preferences.edit().putString("token", value).commit()) throw IOException("Session storage failed")
    }

    fun read(): String? {
        val value = preferences.getString("token", null) ?: return null
        try {
            val parts = value.split('.')
            require(parts.size == 3 && parts[0] == "v1")
            val secret = key(false) ?: run { clear(); return null }
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, secret, GCMParameterSpec(128, Base64.decode(parts[1], Base64.NO_WRAP)))
            return String(cipher.doFinal(Base64.decode(parts[2], Base64.NO_WRAP)), Charsets.UTF_8)
        } catch (_: GeneralSecurityException) {
            clear()
            return null
        } catch (_: IllegalArgumentException) {
            clear()
            return null
        }
    }

    fun clear() {
        if (!preferences.edit().remove("token").commit()) throw IOException("Session storage failed")
    }
}
